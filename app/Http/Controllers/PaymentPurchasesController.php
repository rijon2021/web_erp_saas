<?php

namespace App\Http\Controllers;

use App\Mail\CustomEmail;
use App\Models\Account;
use App\Models\EmailMessage;
use App\Models\PaymentMethod;
use App\Models\PaymentPurchase;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Setting;
use App\Models\sms_gateway;
use App\Models\SMSMessage;
use App\Services\SupplierWallet\SupplierWalletService;
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

class PaymentPurchasesController extends BaseController
{
    use \App\Traits\BuildsPaymentReportStats;

    // ------------- Get All Payments Purchases --------------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Reports_payments_Purchases', PaymentPurchase::class);

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
        $columns = [0 => 'Ref', 1 => 'purchase_id', 2 => 'payment_method_id'];
        $data = [];

        // Check If User Has Permission View  All Records
        $Payments = PaymentPurchase::with('purchase.provider', 'account', 'user')
            ->where('deleted_at', '=', null)
            ->whereBetween('date', [$request->from, $request->to])
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });

        if (! $is_all_warehouses) {
            $Payments->whereHas('purchase', function ($q) use ($warehouse_ids) {
                $q->whereIn('warehouse_id', $warehouse_ids);
            });
        }

        // Multiple Filter
            $Payments->where(function ($query) use ($request) {
                return $query->when($request->filled('provider_id'), function ($query) use ($request) {
                    return $query->whereHas('purchase.provider', function ($q) use ($request) {
                        $q->where('id', '=', $request->provider_id);
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
                            return $query->whereHas('purchase', function ($q) use ($request) {
                                $q->where('Ref', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('payment_method', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('purchase.provider', function ($q) use ($request) {
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
            $item['Ref_Purchase'] = $Payment['purchase']->Ref;
            $item['purchase_id'] = $Payment['purchase']->id;
            $item['provider_name'] = $Payment['purchase']['provider']?->name ?? '---';
            $item['payment_method'] = ! empty($Payment->wallet_transaction_id) ? 'Supplier Wallet' : ($Payment['payment_method']?->name ?? '---');
            $item['wallet_transaction_id'] = $Payment->wallet_transaction_id ?? null;
            $item['montant'] = $Payment->montant;
            $item['account_name'] = $Payment['account'] ? $Payment['account']->account_name : '---';
            $item['user_name'] = $Payment['user'] ? $Payment['user']->username : '---';
            $data[] = $item;
        }

        $suppliers = provider::where('deleted_at', '=', null)->get(['id', 'name']);
        $purchases = Purchase::get(['Ref', 'id']);
        $payment_methods = PaymentMethod::active()->exceptCustomerWallet()->where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'totalRows' => $totalRows,
            'payments' => $data,
            'stats' => $reportStats['stats'],
            'timeseries' => $reportStats['timeseries'],
            'method_breakdown' => $reportStats['method_breakdown'],
            'purchases' => $purchases,
            'suppliers' => $suppliers,
            'payment_methods' => $payment_methods,
        ]);

    }

    // ----------- Store New Payment Purchase --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', PaymentPurchase::class);

        // Supplier-wallet tender (from_wallet=1): the amount leaves the
        // supplier's prepaid balance instead of a cash account, so no account
        // or payment method is required.
        $fromWallet = SupplierWalletService::wantsWallet($request);

        // Optional per-tenant rule; 'nullable' unless the merchant turned on
        // Settings > Features > Require payment account.
        $request->validate(['account_id' => $fromWallet ? 'nullable' : $this->paymentAccountRule()]);

        if ($request['montant'] > 0) {
            try {
                \DB::transaction(function () use ($request, $fromWallet) {
                    $user = Auth::user();
                    // New way: Check user's record_view field (user-level boolean)
                    // Backward compatibility: If record_view is null, fall back to role permission check
                    $view_records = $user->hasRecordView();
                    $purchase = Purchase::findOrFail($request['purchase_id']);

                    // Check If User Has Permission view All Records
                    // Warehouse half of the same rule: record_view says whose documents,
                    // the assigned warehouses say which warehouses they may come from.
                    $this->abortIfDocumentWarehouseDenied($purchase);

                    if (! $view_records) {
                        // Check If User->id === purchase->id
                        $this->authorizeForUser($request->user('api'), 'check_record', $purchase);
                    }

                    $total_paid = $purchase->paid_amount + $request['montant'];
                    $due = $purchase->GrandTotal - $total_paid;

                    if ($due === 0.0 || $due < 0.0) {
                        $payment_statut = 'paid';
                    } elseif ($due !== $purchase->GrandTotal) {
                        $payment_statut = 'partial';
                    } elseif ($due === $purchase->GrandTotal) {
                        $payment_statut = 'unpaid';
                    }

                    $ref = $this->getNumberOrder();
                    $walletTxId = null;
                    if ($fromWallet) {
                        $walletTxId = app(SupplierWalletService::class)->payFromWallet(
                            (int) $purchase->provider_id, (float) $request['montant'], 'purchase_payment', [
                                'reference_type' => Purchase::class,
                                'reference_id' => $purchase->id,
                                'note' => 'Purchase '.$purchase->Ref.' · '.$ref,
                                'date' => $request['date'],
                                'user_id' => Auth::id(),
                            ]
                        )->id;
                    }

                    PaymentPurchase::create([
                        'purchase_id' => $request['purchase_id'],
                        'account_id' => (! $fromWallet && $request['account_id']) ? $request['account_id'] : null,
                        'Ref' => $ref,
                        'date' => $request['date'],
                        'payment_method_id' => $fromWallet ? null : $request['payment_method_id'],
                        'montant' => $request['montant'],
                        'change' => $fromWallet ? 0 : $request['change'],
                        'notes' => $request['notes'],
                        'user_id' => Auth::user()->id,
                        'wallet_transaction_id' => $walletTxId,
                    ]);

                    if (! $fromWallet) {
                        Account::adjustBalance($request['account_id'], -$request['montant']);
                    }

                    $purchase->update([
                        'paid_amount' => $total_paid,
                        'payment_statut' => $payment_statut,
                    ]);

                }, 10);
            } catch (WalletException $e) {
                return $e->toResponse();
            }
        }

        return response()->json(['success' => true, 'message' => 'Payment Create successfully'], 200);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    // ----------- Update Payment Purchases --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', PaymentPurchase::class);

        $toWallet = SupplierWalletService::wantsWallet($request);

        // Optional per-tenant rule; 'nullable' unless the merchant turned on
        // Settings > Features > Require payment account.
        $request->validate(['account_id' => $toWallet ? 'nullable' : $this->paymentAccountRule()]);

        try {
            \DB::transaction(function () use ($id, $request, $toWallet) {
                $user = Auth::user();
                // New way: Check user's record_view field (user-level boolean)
                // Backward compatibility: If record_view is null, fall back to role permission check
                $view_records = $user->hasRecordView();
                $payment = PaymentPurchase::findOrFail($id);

                // Check If User Has Permission view All Records
                // Warehouse half of the same rule: record_view says whose documents,
                // the assigned warehouses say which warehouses they may come from.
                $this->abortIfDocumentWarehouseDenied($payment);

                if (! $view_records) {
                    // Check If User->id === payment->id
                    $this->authorizeForUser($request->user('api'), 'check_record', $payment);
                }

                $purchase = Purchase::whereId($request['purchase_id'])->first();
                $old_total_paid = $purchase->paid_amount - $payment->montant;
                $new_total_paid = $old_total_paid + $request['montant'];

                $due = $purchase->GrandTotal - $new_total_paid;
                if ($due === 0.0 || $due < 0.0) {
                    $payment_statut = 'paid';
                } elseif ($due !== $purchase->GrandTotal) {
                    $payment_statut = 'partial';
                } elseif ($due === $purchase->GrandTotal) {
                    $payment_statut = 'unpaid';
                }

                // Undo the old funding: give a wallet-funded amount back to the
                // wallet, or a cash amount back to its account. Then fund the
                // new amount the way the request asks.
                $wallets = app(SupplierWalletService::class);
                $before = clone $payment; // for the accounting reversal of the old state
                if (! empty($payment->wallet_transaction_id)) {
                    $wallets->reverse((int) $payment->wallet_transaction_id, [
                        'note' => 'Purchase payment '.$payment->Ref.' edited', 'user_id' => Auth::id(),
                    ]);
                } else {
                    Account::adjustBalance($payment->account_id, $payment->montant);
                }

                $walletTxId = null;
                if ($toWallet) {
                    $walletTxId = $wallets->payFromWallet((int) $purchase->provider_id, (float) $request['montant'], 'purchase_payment', [
                        'reference_type' => Purchase::class,
                        'reference_id' => $purchase->id,
                        'note' => 'Purchase '.$purchase->Ref.' · '.$payment->Ref,
                        'date' => $request['date'],
                        'user_id' => Auth::id(),
                    ])->id;
                }

                $payment->update([
                    'date' => $request['date'],
                    'account_id' => (! $toWallet && $request['account_id']) ? $request['account_id'] : null,
                    'payment_method_id' => $toWallet ? null : $request['payment_method_id'],
                    'montant' => $request['montant'],
                    'change' => $toWallet ? 0 : $request['change'],
                    'notes' => $request['notes'],
                    'wallet_transaction_id' => $walletTxId,
                ]);

                // update new account
                if (! $toWallet) {
                    Account::adjustBalance($request['account_id'], -$request['montant']);
                }

                $purchase->paid_amount = $new_total_paid;
                $purchase->payment_statut = $payment_statut;
                $purchase->save();

                // Accounting V2: an edit is a reversal of the old row plus a
                // fresh posting of the new one (model update() fires neither).
                event(new \App\Events\PaymentPurchaseDeleted($before));
                event(new \App\Events\PaymentPurchaseCreated($payment->fresh()));

            }, 10);
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        return response()->json(['success' => true, 'message' => 'Payment Update successfully'], 200);
    }

    // ----------- Delete Payment Purchase --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', PaymentPurchase::class);

        \DB::transaction(function () use ($id, $request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $payment = PaymentPurchase::findOrFail($id);

            // Check If User Has Permission view All Records
            // Warehouse half of the same rule: record_view says whose documents,
            // the assigned warehouses say which warehouses they may come from.
            $this->abortIfDocumentWarehouseDenied($payment);

            if (! $view_records) {
                // Check If User->id === payment->id
                $this->authorizeForUser($request->user('api'), 'check_record', $payment);
            }

            $purchase = Purchase::find($payment->purchase_id);
            $total_paid = $purchase->paid_amount - $payment->montant;
            $due = $purchase->GrandTotal - $total_paid;

            if ($due === 0.0 || $due < 0.0) {
                $payment_statut = 'paid';
            } elseif ($due !== $purchase->GrandTotal) {
                $payment_statut = 'partial';
            } elseif ($due === $purchase->GrandTotal) {
                $payment_statut = 'unpaid';
            }

            PaymentPurchase::whereId($id)->update([
                'deleted_at' => Carbon::now(),
            ]);

            // Wallet-funded: the amount goes back to the supplier wallet
            // (no account to restore — account_id is null on those rows).
            app(SupplierWalletService::class)->reversePaymentPurchase($payment, ['user_id' => Auth::id()]);
            Account::adjustBalance($payment->account_id, $payment->montant);

            // The query-builder soft delete above fires no model event, so
            // the Accounting V2 reversal (cash or wallet) is dispatched here.
            event(new \App\Events\PaymentPurchaseDeleted($payment));

            $purchase->update([
                'paid_amount' => $total_paid,
                'payment_statut' => $payment_statut,
            ]);

        }, 10);

        return response()->json(['success' => true, 'message' => 'Payment Delete successfully'], 200);

    }

    // ----------- Reference order Payment Purchases --------------\\

    public function getNumberOrder()
    {
        $last = DB::table('payment_purchases')->latest('id')->first();

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
                $code = 'INV/PR_1111';
            }

        } else {
            $code = 'INV/PR_1111';
        }

        return $code;
    }

    // ----------- Payment Purchase PDF --------------\\

    public function Payment_purchase_pdf(Request $request, $id)
    {
        $payment = PaymentPurchase::with('purchase', 'purchase.provider')->findOrFail($id);

        $this->authorizeDocumentView($request, $payment);

        $payment_data['purchase_Ref'] = $payment['purchase']->Ref;
        $payment_data['supplier_name'] = $payment['purchase']['provider']?->name ?? '---';
        $payment_data['supplier_phone'] = $payment['purchase']['provider']->phone;
        $payment_data['supplier_adr'] = $payment['purchase']['provider']->adresse;
        $payment_data['supplier_email'] = $payment['purchase']['provider']->email;
        $payment_data['montant'] = $payment->montant;
        $payment_data['Ref'] = $payment->Ref;
        $payment_data['date'] = $payment->date;
        $payment_data['payment_method'] = ! empty($payment->wallet_transaction_id) ? 'Supplier Wallet' : ($payment['payment_method']?->name ?? '---');

        $helpers = new helpers;
        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $helpers->Get_Currency_Code();

        $Html = view('pdf.payments_purchase', [
            'symbol' => $symbol,
            'setting' => $settings,
            // Branch letterhead: the issuing warehouse, falling back to company settings.
            'issuer' => \App\Services\InvoiceIssuerService::resolve(optional($payment->purchase)->warehouse_id, $settings),
            'payment' => $payment_data,
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        $pdf = PDF::loadHTML($Html);

        return $pdf->download('Payment_Purchase.pdf');

    }

    // ------------- Send Payment purchase on Email -----------\\

    public function SendEmail(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PaymentPurchase::class);

        // PaymentPurchase
        $payment = PaymentPurchase::with('purchase.provider')->findOrFail($request->id);

        $helpers = new helpers;
        $currency = $helpers->Get_Currency();

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        // the custom msg of payment_received
        $emailMessage = EmailMessage::getForLocale('payment_sent');

        if ($emailMessage) {
            $message_body = $emailMessage->body;
            $message_subject = $emailMessage->subject;
        } else {
            $message_body = '';
            $message_subject = '';
        }

        $payment_number = $payment->Ref;

        $total_amount = helpers::with_currency($currency, number_format($payment->montant, 2, '.', ','));

        $contact_name = $payment['purchase']['provider']?->name ?? '---';
        $business_name = $settings->CompanyName;

        // receiver email
        $receiver_email = $payment['purchase']['provider']->email;

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
        $this->authorizeForUser($request->user('api'), 'view', PaymentPurchase::class);

        // PaymentPurchase
        $payment = PaymentPurchase::with('purchase.provider')->findOrFail($request->id);

        // settings
        $settings = Setting::where('deleted_at', '=', null)->first();

        $default_sms_gateway = sms_gateway::where('id', $settings->sms_gateway)
            ->where('deleted_at', '=', null)->first();

        $helpers = new helpers;
        $currency = $helpers->Get_Currency();

        // the custom msg of payment_sent
        $smsMessage = SMSMessage::getForLocale('payment_sent');

        if ($smsMessage) {
            $message_text = $smsMessage->text;
        } else {
            $message_text = '';
        }

        $payment_number = $payment->Ref;

        $total_amount = helpers::with_currency($currency, number_format($payment->montant, 2, '.', ','));

        $contact_name = $payment['purchase']['provider']?->name ?? '---';
        $business_name = $settings->CompanyName;

        // receiver phone
        $receiverNumber = $payment['purchase']['provider']->phone;

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
