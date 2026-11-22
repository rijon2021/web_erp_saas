<?php

namespace App\Http\Controllers;

use App\Mail\PaymentReturn;
use App\Models\Account;
use App\Models\PaymentMethod;
use App\Models\PaymentPurchaseReturns;
use App\Models\Provider;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Setting;
use App\Models\sms_gateway;
use App\Services\SupplierWallet\SupplierWalletService;
use App\Services\SupplierWallet\WalletException;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Carbon\Carbon;
use DB;
use GuzzleHttp\Client as Client_termi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PDF;
use Twilio\Rest\Client as Client_Twilio;

class PaymentPurchaseReturnsController extends BaseController
{
    use \App\Traits\BuildsPaymentReportStats;

    // ------------- Get All Payment Purchase Returns --------------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Reports_payments_purchase_Return', PaymentPurchaseReturns::class);

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
        $columns = [0 => 'Ref', 1 => 'purchase_return_id', 2 => 'payment_method_id'];
        $data = [];

        // Check If User Has Permission View  All Records
        $Payments = PaymentPurchaseReturns::with('account', 'PurchaseReturn', 'PurchaseReturn.provider', 'user')
            ->where('deleted_at', '=', null)
            ->whereBetween('date', [$request->from, $request->to])
            ->where(function ($query) use ($view_records) {
                if (! $view_records) {
                    return $query->where('user_id', '=', Auth::user()->id);
                }
            });
        if (! $is_all_warehouses) {
            $Payments->whereHas('PurchaseReturn', function ($q) use ($warehouse_ids) {
                $q->whereIn('warehouse_id', $warehouse_ids);
            });
        }

        // Multiple Filter
            $Payments->where(function ($query) use ($request) {
                return $query->when($request->filled('provider_id'), function ($query) use ($request) {
                    return $query->whereHas('PurchaseReturn.provider', function ($q) use ($request) {
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
                            return $query->whereHas('PurchaseReturn', function ($q) use ($request) {
                                $q->where('Ref', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('payment_method', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('PurchaseReturn.provider', function ($q) use ($request) {
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
            $item['Ref_return'] = $Payment['PurchaseReturn']->Ref;
            $item['purchase_return_id'] = $Payment['PurchaseReturn']->id;
            $item['provider_name'] = $Payment['PurchaseReturn']['provider']?->name ?? '---';
            $item['payment_method'] = ! empty($Payment->wallet_transaction_id) ? 'Supplier Wallet' : ($Payment['payment_method']?->name ?? '---');
            $item['wallet_transaction_id'] = $Payment->wallet_transaction_id ?? null;
            $item['montant'] = $Payment->montant;
            $item['account_name'] = $Payment['account'] ? $Payment['account']->account_name : '---';
            $item['user_name'] = $Payment['user'] ? $Payment['user']->username : '---';
            $data[] = $item;
        }

        $suppliers = Provider::where('deleted_at', '=', null)->get(['id', 'name']);
        $purchase_returns = PurchaseReturn::get(['Ref', 'id']);
        $payment_methods = PaymentMethod::active()->exceptCustomerWallet()->where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'totalRows' => $totalRows,
            'payments' => $data,
            'stats' => $reportStats['stats'],
            'timeseries' => $reportStats['timeseries'],
            'method_breakdown' => $reportStats['method_breakdown'],
            'purchase_returns' => $purchase_returns,
            'suppliers' => $suppliers,
            'payment_methods' => $payment_methods,
        ]);
    }

    // ----------- Store New Payment Purchase Return --------------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', PaymentPurchaseReturns::class);

        // to_wallet=1: the supplier settles the return as wallet credit
        // (a credit note we can spend on later purchases) instead of cash.
        $toWallet = SupplierWalletService::wantsWallet($request);

        // Optional per-tenant rule; 'nullable' unless the merchant turned on
        // Settings > Features > Require payment account.
        $request->validate(['account_id' => $toWallet ? 'nullable' : $this->paymentAccountRule()]);

        if ($request['montant'] > 0) {
            try {
                \DB::transaction(function () use ($request, $toWallet) {
                    $user = Auth::user();
                    // New way: Check user's record_view field (user-level boolean)
                    // Backward compatibility: If record_view is null, fall back to role permission check
                    $view_records = $user->hasRecordView();
                    $PurchaseReturn = PurchaseReturn::findOrFail($request['purchase_return_id']);

                    // Check If User Has Permission view All Records
                    // Warehouse half of the same rule: record_view says whose documents,
                    // the assigned warehouses say which warehouses they may come from.
                    $this->abortIfDocumentWarehouseDenied($PurchaseReturn);

                    if (! $view_records) {
                        // Check If User->id === purchase return->id
                        $this->authorizeForUser($request->user('api'), 'check_record', $PurchaseReturn);
                    }

                    $total_paid = $PurchaseReturn->paid_amount + $request['montant'];
                    $due = $PurchaseReturn->GrandTotal - $total_paid;

                    if ($due === 0.0 || $due < 0.0) {
                        $payment_statut = 'paid';
                    } elseif ($due !== $PurchaseReturn->GrandTotal) {
                        $payment_statut = 'partial';
                    } elseif ($due === $PurchaseReturn->GrandTotal) {
                        $payment_statut = 'unpaid';
                    }

                    $ref = $this->getNumberOrder();
                    $walletTxId = null;
                    if ($toWallet) {
                        $walletTxId = app(SupplierWalletService::class)->creditFromReturn(
                            (int) $PurchaseReturn->provider_id, (float) $request['montant'], [
                                'reference_type' => PurchaseReturn::class,
                                'reference_id' => $PurchaseReturn->id,
                                'note' => 'Purchase return '.$PurchaseReturn->Ref.' · '.$ref,
                                'date' => $request['date'],
                                'user_id' => Auth::id(),
                            ]
                        )->id;
                    }

                    PaymentPurchaseReturns::create([
                        'purchase_return_id' => $request['purchase_return_id'],
                        'account_id' => (! $toWallet && $request['account_id']) ? $request['account_id'] : null,
                        'Ref' => $ref,
                        'date' => $request['date'],
                        'payment_method_id' => $toWallet ? null : $request['payment_method_id'],
                        'montant' => $request['montant'],
                        'change' => $toWallet ? 0 : $request['change'],
                        'notes' => $request['notes'],
                        'user_id' => Auth::user()->id,
                        'wallet_transaction_id' => $walletTxId,
                    ]);

                    if (! $toWallet) {
                        Account::adjustBalance($request['account_id'], $request['montant']);
                    }

                    $PurchaseReturn->update([
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

    // ----------- Update Payment Purchase Return --------------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', PaymentPurchaseReturns::class);

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
                $payment = PaymentPurchaseReturns::findOrFail($id);

                // Check If User Has Permission view All Records
                // Warehouse half of the same rule: record_view says whose documents,
                // the assigned warehouses say which warehouses they may come from.
                $this->abortIfDocumentWarehouseDenied($payment);

                if (! $view_records) {
                    // Check If User->id === payment->id
                    $this->authorizeForUser($request->user('api'), 'check_record', $payment);
                }

                $PurchaseReturn = PurchaseReturn::find($payment->purchase_return_id);
                $old_total_paid = $PurchaseReturn->paid_amount - $payment->montant;
                $new_total_paid = $old_total_paid + $request['montant'];
                $due = $PurchaseReturn->GrandTotal - $new_total_paid;

                if ($due === 0.0 || $due < 0.0) {
                    $payment_statut = 'paid';
                } elseif ($due !== $PurchaseReturn->GrandTotal) {
                    $payment_statut = 'partial';
                } elseif ($due === $PurchaseReturn->GrandTotal) {
                    $payment_statut = 'unpaid';
                }

                // Undo the old settlement (wallet credit or cash), then apply the new one.
                $wallets = app(SupplierWalletService::class);
                if (! empty($payment->wallet_transaction_id)) {
                    $wallets->reverse((int) $payment->wallet_transaction_id, [
                        'note' => 'Return payment '.$payment->Ref.' edited', 'user_id' => Auth::id(),
                    ]);
                } else {
                    Account::adjustBalance($payment->account_id, -$payment->montant);
                }

                $walletTxId = null;
                if ($toWallet) {
                    $walletTxId = $wallets->creditFromReturn((int) $PurchaseReturn->provider_id, (float) $request['montant'], [
                        'reference_type' => PurchaseReturn::class,
                        'reference_id' => $PurchaseReturn->id,
                        'note' => 'Purchase return '.$PurchaseReturn->Ref.' · '.$payment->Ref,
                        'date' => $request['date'],
                        'user_id' => Auth::id(),
                    ])->id;
                }

                $payment->update([
                    'date' => $request['date'],
                    'payment_method_id' => $toWallet ? null : $request['payment_method_id'],
                    'account_id' => (! $toWallet && $request['account_id']) ? $request['account_id'] : null,
                    'montant' => $request['montant'],
                    'change' => $toWallet ? 0 : $request['change'],
                    'notes' => $request['notes'],
                    'wallet_transaction_id' => $walletTxId,
                ]);

                // update new account
                if (! $toWallet) {
                    Account::adjustBalance($request['account_id'], $request['montant']);
                }

                $PurchaseReturn->update([
                    'paid_amount' => $new_total_paid,
                    'payment_statut' => $payment_statut,
                ]);

            }, 10);
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        return response()->json(['success' => true, 'message' => 'Payment Update successfully'], 200);
    }

    // ----------- Remove Payment Purchase Return --------------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', PaymentPurchaseReturns::class);

        \DB::transaction(function () use ($id, $request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $payment = PaymentPurchaseReturns::findOrFail($id);

            // Check If User Has Permission view All Records
            // Warehouse half of the same rule: record_view says whose documents,
            // the assigned warehouses say which warehouses they may come from.
            $this->abortIfDocumentWarehouseDenied($payment);

            if (! $view_records) {
                // Check If User->id === payment->id
                $this->authorizeForUser($request->user('api'), 'check_record', $payment);
            }

            $PurchaseReturn = PurchaseReturn::find($payment->purchase_return_id);
            $total_paid = $PurchaseReturn->paid_amount - $payment->montant;
            $due = $PurchaseReturn->GrandTotal - $total_paid;

            if ($due === 0.0 || $due < 0.0) {
                $payment_statut = 'paid';
            } elseif ($due !== $PurchaseReturn->GrandTotal) {
                $payment_statut = 'partial';
            } elseif ($due === $PurchaseReturn->GrandTotal) {
                $payment_statut = 'unpaid';
            }

            PaymentPurchaseReturns::whereId($id)->update([
                'deleted_at' => Carbon::now(),
            ]);

            // Wallet-credited: take the credit back out of the supplier wallet.
            app(SupplierWalletService::class)->reversePaymentPurchaseReturn($payment, ['user_id' => Auth::id()]);
            Account::adjustBalance($payment->account_id, -$payment->montant);

            $PurchaseReturn->update([
                'paid_amount' => $total_paid,
                'payment_statut' => $payment_statut,
            ]);

        }, 10);

        return response()->json(['success' => true, 'message' => 'Payment Delete successfully'], 200);
    }

    // ------------- Send Payment Purchase Return To Email -----------\\

    public function SendEmail(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'view', PaymentPurchaseReturns::class);

        $payment['id'] = $request->id;
        $payment['Ref'] = $request->Ref;
        $settings = Setting::where('deleted_at', '=', null)->first();
        $payment['company_name'] = $settings->CompanyName;

        $pdf = $this->payment_return($request, $payment['id']);
        $this->Set_config_mail(); // Set_config_mail => BaseController
        Mail::to($request->to)->send(new PaymentReturn($payment, $pdf));

        return response()->json(['message' => 'Email sent successfully'], 200);
        // return $mail;
    }

    // ----------- Number Order Payment Purchase Return --------------\\

    public function getNumberOrder()
    {
        $last = DB::table('payment_purchase_returns')->latest('id')->first();

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
                $code = 'INV/RT_1111';
            }

        } else {
            $code = 'INV/RT_1111';
        }

        return $code;
    }

    // ----------- Payment Purchase Return PDF --------------\\

    public function payment_return(Request $request, $id)
    {
        $payment = PaymentPurchaseReturns::with('PurchaseReturn', 'PurchaseReturn.provider')->findOrFail($id);

        $this->authorizeDocumentView($request, $payment);

        $payment_data['return_Ref'] = $payment['PurchaseReturn']->Ref;
        $payment_data['supplier_name'] = $payment['PurchaseReturn']['provider']?->name ?? '---';
        $payment_data['supplier_phone'] = $payment['PurchaseReturn']['provider']->phone;
        $payment_data['supplier_adr'] = $payment['PurchaseReturn']['provider']->adresse;
        $payment_data['supplier_email'] = $payment['PurchaseReturn']['provider']->email;
        $payment_data['montant'] = $payment->montant;
        $payment_data['Ref'] = $payment->Ref;
        $payment_data['date'] = $payment->date;
        $payment_data['payment_method'] = $payment['payment_method']?->name ?? '---';

        $helpers = new helpers;
        $settings = Setting::where('deleted_at', '=', null)->first();
        $symbol = $helpers->Get_Currency_Code();

        $Html = view('pdf.Payment_Purchase_Return', [
            'symbol' => $symbol,
            'setting' => $settings,
            // Branch letterhead: the issuing warehouse, falling back to company settings.
            'issuer' => \App\Services\InvoiceIssuerService::resolve(optional($payment->PurchaseReturn)->warehouse_id, $settings),
            'payment' => $payment_data,
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        $pdf = PDF::loadHTML($Html);

        return $pdf->download('Payment_Purchase_Return.pdf');

    }

    // -------------------Sms Notifications -----------------\\
    public function Send_SMS(Request $request)
    {
        $payment = PaymentPurchaseReturns::with('PurchaseReturn', 'PurchaseReturn.provider')->findOrFail($request->id);
        $settings = Setting::where('deleted_at', '=', null)->first();
        $gateway = sms_gateway::where('id', $settings->sms_gateway)
            ->where('deleted_at', '=', null)->first();

        $this->authorizeDocumentView($request, $payment);
        $url = $payment->shareUrl();
        $receiverNumber = $payment['PurchaseReturn']['provider']->phone;
        $message = 'Dear'.' '.$payment['PurchaseReturn']['provider']?->name." \n We are contacting you in regard to a Payment #".$payment['PurchaseReturn']->Ref.' '.$url.' '."that has been created on your account. \n We look forward to conducting future business with you.";

        if (! $gateway) {
            return response()->json(['status' => 'error', 'message' => 'SMS gateway is not configured'], 500);
        }

        // credentials are resolved from the tenant's own sms_settings table
        try {
            app(\App\Services\SmsOtpSender::class)->sendVia($gateway->title, $receiverNumber, $message);
        } catch (\Throwable $e) {
            Log::error('SMS Error: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }

        return response()->json(['success' => true]);
    }
}
