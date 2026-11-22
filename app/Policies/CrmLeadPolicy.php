<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/** CRM permissions (crm_*). Lead visibility rules live in CrmLeadService::visible(). */
class CrmLeadPolicy
{
    use HandlesAuthorization;

    public function view(User $user)
    {
        return $this->check($user, 'crm_leads_view');
    }

    public function create(User $user)
    {
        return $this->check($user, 'crm_leads_add');
    }

    public function update(User $user)
    {
        return $this->check($user, 'crm_leads_edit');
    }

    public function delete(User $user)
    {
        return $this->check($user, 'crm_leads_delete');
    }

    public function dashboard(User $user)
    {
        return $this->check($user, 'crm_dashboard');
    }

    /** See (and work) every rep's leads, not just their own + the unassigned pool. */
    public function viewAll(User $user)
    {
        return $this->check($user, 'crm_view_all');
    }

    public function importExport(User $user)
    {
        return $this->check($user, 'crm_import_export');
    }

    public function settings(User $user)
    {
        return $this->check($user, 'crm_settings');
    }

    /** CRM customer list and the Customer 360 page. */
    public function customers(User $user)
    {
        return $this->check($user, 'crm_customers_view');
    }

    /** CRM Reports: funnel, win / loss, forecast, leaderboard, activities, sources. */
    public function reports(User $user)
    {
        return $this->check($user, 'crm_reports');
    }

    /** Set monthly / quarterly sales targets. */
    public function targets(User $user)
    {
        return $this->check($user, 'crm_targets');
    }

    /** Create and manage the public lead-capture web forms. */
    public function webForms(User $user)
    {
        return $this->check($user, 'crm_web_forms');
    }

    /** See conversations and send email / SMS / WhatsApp from the CRM. */
    public function messages(User $user)
    {
        return $this->check($user, 'crm_messages');
    }

    /** Add / edit / remove a customer's contact people. */
    public function manageContacts(User $user)
    {
        return $this->check($user, 'crm_contacts_manage');
    }

    private function check(User $user, $name)
    {
        $permission = Permission::where('name', $name)->first();

        return $permission ? $user->hasRole($permission->roles) : false;
    }
}
