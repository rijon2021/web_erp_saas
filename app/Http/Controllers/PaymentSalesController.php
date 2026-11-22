<?php

namespace App\Http\Controllers;

use App\Mail\CustomEmail;
use App\Models\Account;
use App\Models\Client;
use App\Models\EmailMessage;
use App\Models\PaymentMethod;
use App\Models\PaymentSale;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\sms_gateway;
use App\Models\SMSMessage;
use App\Services\CustomerWallet\CustomerWalletService;
use App\Services\SupplierWallet\WalletException;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Carbon\Carbon;
use DB;
use GuzzleHttp\Client as Client_guzzle;
use GuzzleHttp\Client as Client_termi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Infobip\Api\SendSmsApi;
use Infobip\Configuration;
use Infobip\Model\SmsAdvancedTextualRequest;
use Infobip\Model\SmsDestination;
use Infobip\Model\SmsTextualMessage;
use PDF;
use Twilio\Rest\Client as Client_Twilio;

class PaymentSalesController extends BaseController
{
    use \App\Traits\BuildsPaymentReportStats;

    // ------------- Get All Payments Sales --------------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Reports_payments_Sales', PaymentSale::class);

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        $helpers = new helpers;
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        
        $warehouse_ids = [];
        $is_all_warehouses = $user->is_all_warehouses;
        // If the user is restricted, fetch their assigned warehouse IDs once and reuse below.
        if (! $is_all_warehouses) {
            $warehouse_ids = UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->toArray();
        }
        
        // Filter fields With Params to retriever
        $param = [0 => 'like', 1 => '=', 2 => '='];
        $columns = [0 => 'Ref', 1 => 'sale_id', 2 => 'payment_method_id'];
        $data = [];

        // Check If User Has Permission View  All Records
        $Payments = PaymentSale::with('sale.client', 'account', 'user')
            ->where('deleted_at', '=', null)
            ->whereBetween('date', [$request->from, $request->to])
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });
            // ✅ Restrict by user's warehouses (through sale.warehouse_id)
        if (! $is_all_warehouses) {
            $Payments->whereHas('sale', function ($q) use ($warehouse_ids) {
                $q->whereIn('warehouse_id', $warehouse_ids);
            });
        }
        // Multiple Filter
            $Payments->where(function ($query) use ($request) {
                return $query->when($request->filled('client_id'), function ($query) use ($request) {
                    return $query->whereHas('sale.client', function ($q) use ($request) {
                        $q->where('id', '=', $request->client_id);
                    });
                });
            });
        $Filtred = $helpers->filter($Payments, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('date', 'LIKE', "%{$request->search}%")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('sale', function ($q) use ($request) {
                                $q->where('Ref', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('payment_method', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('sale.client', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });

        $totalRows = $Filtred->count();
        $reportStats = $this->paymentReportStats($Filtred);
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $Payments = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($Payments as $Payment) {

            $item['id'] = $Payment->id;
            $item['date'] = $Payment->date;
            $item['Ref'] = $Payment->Ref;
            $item['Ref_Sale'] = $Payment['sale']->Ref;
            $item['sale_id'] = $Payment['sale']->id;
            $item['client_name'] = $Payment['sale']['client']?->name ?? '---';
            $item['payment_method'] = $Payment['payment_method']?->name ?? '---';
            $item['wallet_transaction_id'] = $Payment->wallet_transaction_id ?? null;
            $item['montant'] = $Payment->montant;
            $item['account_name'] = $Payment['account'] ? $Payment['account']->account_name : '---';
            $item['user_name'] = $Payment['user'] ? $Payment['user']->username : '---';
            $data[] = $item;
        }

        $clients = Client::where('deleted_at', '=', null)->get(['id', 'name']);
        $sales = Sale::get(['Ref', 'id']);
        $payment_methods = PaymentMethod::active()->where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'totalRows' => $totalRows,
            'payments' => $data,
            'stats' => $reportStats['stats'],
            'timeseries' => $reportStats['timeseries'],
            'method_breakdown' => $reportStats['method_breakdown'],
            'sales' => $sales,
            'clients' => $clients,
            'payment_methods' => $payment_methods,
        ]);

    }

    // ----------- Store new Payment Sale --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', PaymentSale::class);

        // Customer-wallet tender: the amount leaves the customer's wallet
        // instead of landing in a cash account, so no account is needed.
        $wallets = app(CustomerWalletService::class);
        $fromWallet = $wallets->isWalletMethod($request['payment_method_id']);

        // Optional per-tenant rule; 'nullable' unless the merchant turned on
        // Settings > Features > Require payment account.
        $request->validate(['account_id' => $fromWallet ? 'nullable' : $this->paymentAccountRule()]);

        try {
        \DB::transaction(function () use ($request, $wallets, $fromWallet) {
            $helpers = new helpers;
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            // Locked: two payments on the same sale at once must not both read
            // the old paid_amount (lost update / payment status wrong).
            $sale = Sale::lockForUpdate()->findOrFail($request['sale_id']);

            // Check If User Has Permission view All Records
            // Warehouse half of the same rule: record_view says whose documents,
            // the assigned warehouses say which warehouses they may come from.
            $this->abortIfDocumentWarehouseDenied($sale);

            if (! $view_records) {
                // Check If User->id === sale->id
                $this->authorizeForUser($request->user('api'), 'check_record', $sale);
            }

            // Server-side overpayment guard (the form already caps the amount at
            // the due; a direct API call could not be trusted to). Only `montant`
            // is recorded here (payments[] is not read by this endpoint), and it
            // is base currency like GrandTotal / paid_amount.
            $requested = max(0, (float) $request['montant']);
            $due = (float) $sale->GrandTotal - (float) $sale->paid_amount;
            // The form's cap is the due formatted to price_decimals, so allow
            // half a display unit of rounding (never less than a cent).
            $tolerance = max(0.01, 0.5 / (10 ** max(0, (int) helpers::price_decimals())));
            if ($requested > $due + $tolerance) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'montant' => 'Paying amount is greater than the amount due.',
                ]);
            }

            try {

                $total_paid = $sale->paid_amount + $request['montant'];
                $payment_statut = Sale::paymentStatusFor($sale->GrandTotal, $total_paid);

                if ($request['montant'] > 0) {
                    // All payment methods are now handled the same way; no online Stripe charge is performed here.
                    $ref = app('App\Http\Controllers\PaymentSalesController')->getNumberOrder();
                    $walletTxId = null;
                    if ($fromWallet) {
                        $walletTxId = $wallets->payFromWallet((int) $sale->client_id, (float) $request['montant'], 'sale_payment', [
                            'reference_type' => Sale::class,
                            'reference_id' => $sale->id,
                            'note' => 'Sale '.$sale->Ref.' · '.$ref,
                            'date' => $request['date'],
                            'user_id' => Auth::id(),
                        ])->id;
                    }

                    PaymentSale::create([
                        'sale_id' => $sale->id,
                        'Ref' => $ref,
                        'date' => $request['date'],
                        'account_id' => (! $fromWallet && $request['account_id']) ? $request['account_id'] : null,
                        'payment_method_id' => $request['payment_method_id'],
                        'montant' => $request['montant'],
                        'change' => $fromWallet ? 0 : $request['change'],
                        'notes' => $request['notes'],
                        'user_id' => Auth::user()->id,
                    ] + CustomerWalletService::txColumn($walletTxId));

                    if (! $fromWallet) {
                        Account::adjustBalance($request['account_id'], $request['montant']);
                    }

                    $sale->update([
                        'paid_amount' => $total_paid,
                        'payment_statut' => $payment_statut,
                    ]);
                }

            } catch (Exception $e) {
                return response()->json(['message' => $e->getMessage()], 500);
            }

        }, 10);
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        // Recalculate commissions after a payment is recorded. Commission rules with a
        // "Paid Amount" source depend on paid_amount, which only changes here when payment
        // is taken separately from sale creation. calculateForSale() is idempotent, so it
        // will only create rows that do not already exist for this sale/rule/agent.
        try {
            $paidSale = Sale::find($request['sale_id']);
            if ($paidSale && $paidSale->statut === 'completed') {
                app(\App\Services\CommissionService::class)->calculateForSale($paidSale);
            }
        } catch (\Throwable $e) {
            \Log::warning('Commission calculation failed after payment (non-blocking): '.$e->getMessage(), ['sale_id' => $request['sale_id']]);
        }

        // Fire the WhatsApp "payment received" automation (non-blocking).
        try {
            $paidSale = Sale::find($request['sale_id']);
            if ($paidSale) {
                \App\Services\WhatsAppNotifier::paymentReceived($paidSale);
            }
        } catch (\Throwable $e) {
            \Log::warning('WhatsApp payment notification failed (non-blocking): '.$e->getMessage());
        }

        return response()->json(['success' => true, 'message' => 'Payment Create successfully'], 200);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // ----------- Update Payments Sale --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', PaymentSale::class);

        $wallets = app(CustomerWalletService::class);
        $toWallet = $wallets->isWalletMethod($request['payment_method_id']);

        // Optional per-tenant rule; 'nullable' unless the merchant turned on
        // Settings > Features > Require payment account.
        $request->validate(['account_id' => $toWallet ? 'nullable' : $this->paymentAccountRule()]);

        try {
        \DB::transaction(function () use ($id, $request, $wallets, $toWallet) {
            $helpers = new helpers;
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $payment = PaymentSale::findOrFail($id);

            // Check If User Has Permission view All Records
            // Warehouse half of the same rule: record_view says whose documents,
            // the assigned warehouses say which warehouses they may come from.
            $this->abortIfDocumentWarehouseDenied($payment);

            if (! $view_records) {
                // Check If User->id === payment->id
                $this->authorizeForUser($request->user('api'), 'check_record', $payment);
            }

            $sale = Sale::find($payment->sale_id);
            $old_total_paid = $sale->paid_amount - $payment->montant;
            $new_total_paid = $old_total_paid + $request['montant'];

            $payment_statut = Sale::paymentStatusFor($sale->GrandTotal, $new_total_paid);

            try {
                if ($payment->payment_method_id != 1 && $payment->payment_method_id != '1') {

                    // Reverse the old account and credit the new one only on the
                    // path that actually rewrites the payment. Card payments fall
                    // through this branch untouched, so reversing before the check
                    // would take the money off the account with nothing putting it
                    // back. A wallet-funded payment gives its amount back to the
                    // customer's wallet instead.
                    $before = clone $payment; // for the accounting reversal of the old state
                    if (! empty($payment->wallet_transaction_id)) {
                        $wallets->reversePayment($payment, 'Sale payment', ['note' => 'Sale payment '.$payment->Ref.' edited']);
                    } else {
                        Account::adjustBalance($payment->account_id, -$payment->montant);
                    }

                    $walletTxId = null;
                    if ($toWallet && (float) $request['montant'] > 0) {
                        $walletTxId = $wallets->payFromWallet((int) $sale->client_id, (float) $request['montant'], 'sale_payment', [
                            'reference_type' => Sale::class,
                            'reference_id' => $sale->id,
                            'note' => 'Sale '.$sale->Ref.' · '.$payment->Ref,
                            'date' => $request['date'],
                            'user_id' => Auth::id(),
                        ])->id;
                    }

                    $payment->update([
                        'date' => $request['date'],
                        'payment_method_id' => $request['payment_method_id'],
                        'account_id' => (! $toWallet && $request['account_id']) ? $request['account_id'] : null,
                        'montant' => $request['montant'],
                        'change' => $toWallet ? 0 : $request['change'],
                        'notes' => $request['notes'],
                    ] + ((! empty($before->wallet_transaction_id) || $walletTxId) ? ['wallet_transaction_id' => $walletTxId] : []));

                    if (! $toWallet) {
                        Account::adjustBalance($request['account_id'], $request['montant']);
                    }

                    $sale->update([
                        'paid_amount' => $new_total_paid,
                        'payment_statut' => $payment_statut,
                    ]);

                    // Accounting V2: an edit is a reversal of the old row plus a
                    // fresh posting of the new one (model update() fires neither).
                    event(new \App\Events\PaymentDeleted($before));
                    event(new \App\Events\PaymentCreated($payment->fresh()));

                }

            } catch (Exception $e) {
                return response()->json(['message' => $e->getMessage()], 500);
            }

        }, 10);
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        return response()->json(['success' => true, 'message' => 'Payment Update successfully'], 200);
    }

    // ----------- Delete Payment Sales --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', PaymentSale::class);

        \DB::transaction(function () use ($id, $request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $payment = PaymentSale::findOrFail($id);

            // Check If User Has Permission view All Records
            // Warehouse half of the same rule: record_view says whose documents,
            // the assigned warehouses say which warehouses they may come from.
            $this->abortIfDocumentWarehouseDenied($payment);

            if (! $view_records) {
                // Check If User->id === payment->id
                $this->authorizeForUser($request->user('api'), 'check_record', $payment);
            }

            $sale = Sale::find($payment->sale_id);
            $total_paid = $sale->paid_amount - $payment->montant;
            $payment_statut = Sale::paymentStatusFor($sale->GrandTotal, $total_paid);

            PaymentSale::whereId($id)->update([
                'deleted_at' => Carbon::now(),
            ]);

            if (! empty($payment->wallet_transaction_id)) {
                // Wallet-funded: the amount goes back to the customer's wallet.
                app(CustomerWalletService::class)->reversePayment($payment, 'Sale payment');
            } else {
                Account::adjustBalance($payment->account_id, -$payment->montant);
            }

            // Accounting V2: the query-builder delete above fires no model
            // hook, so post the reversal journal explicitly.
            event(new \App\Events\PaymentDeleted($payment));

            $sale->update([
                'paid_amount' => $total_paid,
                'payment_statut' => $payment_statut,
            ]);

        }, 10);

        return response()->json(['success' => true, 'message' => 'Payment Delete successfully'], 200);

    }

    // ----------- Reference order Payment Sales --------------\\

    public function getNumberOrder()
    {
        $last = DB::table('payment_sales')->latest('id')->first();

        if ($last) {
            $item = (string) ($last->Ref ?? '');

            // Accept either '_' or '-' as separator without assuming array index exists
            if (strpos($item, '_') !== false) {
                $nwMsg = explode('_', $item);
                $sep = '_';
            } elseif (strpos($item, '-') !== false) {
                $nwMsg = explode('-', $item);
                $sep = '-';
            } else {
                $nwMsg = [];
                $sep = '_';
            }

            if (isset($nwMsg[0], $nwMsg[1]) && is_numeric($nwMsg[1])) {
                $inMsg = $nwMsg[1] + 1;
                $code = $nwMsg[0].$sep.$inMsg;
            } else {
                $code = 'INV/SL_1111';
            }

        } else {
            $code = 'INV/SL_1111';
        }

        return $code;
    }

    // ----------- Payment Sale PDF --------------\\

    public function payment_sale(Request $request, $id)
    {
        $payment = PaymentSale::with('sale', 'sale.client')->findOrFail($id);

        $this->authorizeDocumentView($request, $payment);

        $payment_data['sale_Ref'] = $payment['sale']->Ref;
        $payment_data['client_name'] = $payment['sale']['client']?->name ?? '---';
        $payment_data['client_phone'] = $payment['sale']['client']->phone;
        $payment_data['client_adr'] = $payment['sale']['client']->adresse;
        $payment_data['client_email'] = $payment['sale']['client']->email;
        $payment_data['montant'] = $payment->montant;
        $payment_data['Ref'] = $payment->Ref;
        $payment_data['date'] = $payment->date;
        $payment_data['payment_method'] = $payment['payment_method']?->name ?? '---';

        $helpers = new helpers;
        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $helpers->Get_Currency_Code();

        $Html = view('pdf.payment_sale', [
            'symbol' => $symbol,
            'setting' => $settings,
            // Branch letterhead: the issuing warehouse, falling back to company settings.
            'issuer' => \App\Services\InvoiceIssuerService::resolve(optional($payment->sale)->warehouse_id, $settings),
            'payment' => $payment_data,
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        $pdf = PDF::loadHTML($Html);

        return $pdf->download('Payment_Sale.pdf');

    }

    // ------------- Send Payment Sale on Email -----------\\

    public function SendEmail(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PaymentSale::class);
        // PaymentSale
        $payment = PaymentSale::with('sale.client')->findOrFail($request->id);

        $helpers = new helpers;
        $currency = $helpers->Get_Currency();

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        // the custom msg of payment_received
        $emailMessage = EmailMessage::getForLocale('payment_received');

        if ($emailMessage) {
            $message_body = $emailMessage->body;
            $message_subject = $emailMessage->subject;
        } else {
            $message_body = '';
            $message_subject = '';
        }

        $payment_number = $payment->Ref;

        $total_amount = helpers::with_currency($currency, number_format($payment->montant, 2, '.', ','));

        $contact_name = $payment['sale']['client']?->name ?? '---';
        $business_name = $settings->CompanyName;

        // receiver email
        $receiver_email = $payment['sale']['client']->email;

        // replace the text with tags
        $message_body = str_replace('{contact_name}', e((string) $contact_name), $message_body);
        $message_body = str_replace('{business_name}', e((string) $business_name), $message_body);
        $message_body = str_replace('{payment_number}', $payment_number, $message_body);
        $message_body = str_replace('{total_amount}', $total_amount, $message_body);

        $email['subject'] = $message_subject;
        $email['body'] = $message_body;
        $email['company_name'] = $business_name;

        $this->Set_config_mail();

        Mail::to($receiver_email)->send(new CustomEmail($email));

        return response()->json(['message' => 'Email sent successfully'], 200);

        // return $mail;
    }

    // -------------------Sms Notifications -----------------\\

    public function Send_SMS(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PaymentSale::class);

        // PaymentSale
        $payment = PaymentSale::with('sale.client')->findOrFail($request->id);

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        $default_sms_gateway = sms_gateway::where('id', $settings->sms_gateway)
            ->where('deleted_at', '=', null)->first();

        $helpers = new helpers;
        $currency = $helpers->Get_Currency();

        // the custom msg of payment_received
        $smsMessage = SMSMessage::getForLocale('payment_received');

        if ($smsMessage) {
            $message_text = $smsMessage->text;
        } else {
            $message_text = '';
        }

        $payment_number = $payment->Ref;

        $total_amount = helpers::with_currency($currency, number_format($payment->montant, 2, '.', ','));

        $contact_name = $payment['sale']['client']?->name ?? '---';
        $business_name = $settings->CompanyName;

        // receiver phone
        $receiverNumber = $payment['sale']['client']->phone;

        // replace the text with tags
        $message_text = str_replace('{contact_name}', $contact_name, $message_text);
        $message_text = str_replace('{business_name}', $business_name, $message_text);
        $message_text = str_replace('{payment_number}', $payment_number, $message_text);
        $message_text = str_replace('{total_amount}', $total_amount, $message_text);

        if (! $default_sms_gateway) {
            return response()->json(['status' => 'error', 'message' => 'SMS gateway is not configured'], 500);
        }

        // credentials are resolved from the tenant's own sms_settings table
        try {
            app(\App\Services\SmsOtpSender::class)->sendVia($default_sms_gateway->title, $receiverNumber, $message_text);
        } catch (\Throwable $e) {
            Log::error('SMS Error: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['success' => true]);
    }
}
