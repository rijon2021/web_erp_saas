<?php

namespace App\Http\Controllers;

use App\Traits\ScopesWarehouseAccess;
use Config;
use DB;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class BaseController extends Controller
{
    use ScopesWarehouseAccess;

    /**
     * Gate for the Print & PDF endpoints, which open one document by id.
     *
     * Same three checks the detail endpoints apply: the user holds one of
     * $abilities on the document's policy (several are accepted so a POS
     * cashier can reprint a receipt without Sales_view), the document comes
     * from a warehouse they are assigned to, and — without record_view — they
     * own it. A document reached through a verified share link skips the gate.
     */
    protected function authorizeDocumentView(Request $request, Model $document, array $abilities = ['view']): void
    {
        if ($this->isSharedDocument($request, $document)) {
            return;
        }

        $user = $request->user('api');
        $class = get_class($document);

        if (! $user || ! collect($abilities)->contains(fn ($ability) => $user->can($ability, $class))) {
            throw new AuthorizationException;
        }

        $this->abortIfDocumentWarehouseDenied($document);

        $policy = Gate::getPolicyFor($class);
        if (! $user->hasRecordView() && $policy && method_exists($policy, 'check_record')) {
            $this->authorizeForUser($user, 'check_record', $document);
        }
    }

    /**
     * True when DocumentShareController resolved this request's share token to
     * $document. Request attributes are server-side only, so a client cannot
     * set this flag itself.
     */
    protected function isSharedDocument(Request $request, Model $document): bool
    {
        $shared = $request->attributes->get('shared_document');

        return $shared instanceof Model && $shared->is($document);
    }

    /**
     * Validation rule for a payment's cash/bank account.
     *
     * The accounts module is optional, so this is 'nullable' by default and a
     * payment recorded without an account is still complete — it just belongs to
     * no account. A merchant who does run tills can switch on
     * Settings > Features > "Require payment account" to stop unattributed
     * payments being saved, and this returns 'required' for them.
     */
    protected function paymentAccountRule(): string
    {
        try {
            if (Schema::hasColumn('settings', 'require_payment_account')) {
                $required = (bool) \App\Models\Setting::query()
                    ->whereNull('deleted_at')
                    ->value('require_payment_account');

                if ($required) {
                    return 'required|exists:accounts,id';
                }
            }
        } catch (\Throwable $e) {
            // Settings unavailable (boot, migrations): fall back to optional,
            // which is the behaviour every install had before this setting.
        }

        return 'nullable|exists:accounts,id';
    }

    public function sendResponse($result, $msg)
    {
        $response = [
            'success' => true,
            'message' => $msg,
        ];
        if (! empty($result)) {
            $response['data'] = $result;
        }

        return response()->json($response, 200);
    }

    public function sendError($error_msg, $error = null)
    {
        $response = [
            'success' => false,
            'message' => $error_msg,
        ];
        if (isset($error)) {
            $response['errors'] = $error;
        }

        return response()->json($response, 400);
    }

    //    Set cookie
    public function setCookie($cookie_name, $cookie_value)
    {
        $domain = ($_SERVER['SERVER_NAME'] != 'localhost') ? $_SERVER['SERVER_NAME'] : '.'.$_SERVER['SERVER_NAME'];
        $this->destroyCookie($cookie_name);
        setcookie($cookie_name, $cookie_value, time() + 2147483647, '/', $domain);
    }

    // Get cookie
    public function getCookie($cookie_name)
    {
        if (isset($_COOKIE[$cookie_name])) {
            return $_COOKIE[$cookie_name];
        } else {
            return false;
        }
    }

    // Has cookie
    public function hasCookie($cookie_name)
    {
        if (isset($_COOKIE[$cookie_name])) {
            return true;
        } else {
            return false;
        }
    }

    // Destroy cookie
    public function destroyCookie($cookie_name)
    {
        $domain = ($_SERVER['SERVER_NAME'] != 'localhost') ? $_SERVER['SERVER_NAME'] : '.'.$_SERVER['SERVER_NAME'];
        if (isset($_COOKIE[$cookie_name])) {
            unset($_COOKIE[$cookie_name]);
            setcookie($cookie_name, '', time() - 2147483647, '/', $domain);

        }
    }

    // Clear cookie
    public function clearCookie()
    {
        $domain = ($_SERVER['SERVER_NAME'] != 'localhost') ? $_SERVER['SERVER_NAME'] : '.'.$_SERVER['SERVER_NAME'];
        if (isset($_COOKIE['Stocky_token'])) {
            unset($_COOKIE['Stocky_token']);
            setcookie('Stocky_token', '', time() - 2147483647, '/', $domain); // empty value and old timestamp
        }
    }

    // Set config mail
    public function Set_config_mail()
    {

        $server = DB::table('servers')->where('deleted_at', '=', null)->first();
        $settings = DB::table('settings')->where('deleted_at', '=', null)->first();
        if ($server && $settings) { // checking if table is not empty
            // Prefer sender_email from server, fallback to settings email
            $fromEmail = ($server->sender_email ?? null) ?: $settings->email;
            
            $config = [
                    'driver' => $server->mail_mailer,
                    'host' => $server->host,
                    'port' => $server->port,
                    'from' => ['address' => $fromEmail, 'name' => $server->sender_name],
                    'encryption' => $server->encryption,
                    'username' => $server->username,
                    'password' => $server->password,
                    'sendmail' => '/usr/sbin/sendmail -bs',
                    'pretend' => false,
                    'stream' => [
                        'ssl' => [
                            'allow_self_signed' => true,
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                        ],
                    ],
                ];
            Config::set('mail', $config);
        }
    }
}
