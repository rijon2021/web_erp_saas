<?php

namespace App\Http\Controllers;

use App\Models\CrmLead;
use App\Models\CrmLeadSource;
use App\Models\CrmLeadStatus;
use App\Models\CrmSetting;
use App\Models\User;
use App\Services\Crm\CrmLeadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CSV import of leads in two steps: preview (headers, sample rows and a
 * suggested column → field mapping), then import with the mapping the user
 * confirmed. Comma, semicolon or tab separated; UTF-8 (BOM tolerated).
 */
class CrmLeadImportController extends Controller
{
    private const MAX_ROWS = 5000;

    /** Importable fields and the header names that map to them automatically. */
    private const FIELDS = [
        'full_name' => ['full name', 'name', 'contact', 'contact name', 'nom', 'nombre'],
        'first_name' => ['first name', 'firstname', 'given name', 'prenom', 'prénom'],
        'last_name' => ['last name', 'lastname', 'surname', 'family name', 'nom de famille', 'apellido'],
        'company_name' => ['company', 'company name', 'organization', 'organisation', 'business', 'societe', 'société', 'empresa'],
        'job_title' => ['job title', 'title', 'position', 'role', 'poste'],
        'email' => ['email', 'e-mail', 'mail', 'email address'],
        'phone' => ['phone', 'telephone', 'téléphone', 'mobile', 'phone number', 'tel', 'gsm', 'whatsapp'],
        'website' => ['website', 'web', 'site', 'url'],
        'industry' => ['industry', 'sector', 'secteur'],
        'address' => ['address', 'adresse', 'street'],
        'city' => ['city', 'ville', 'town', 'ciudad'],
        'country' => ['country', 'pays', 'pais', 'país'],
        'source' => ['source', 'lead source', 'channel'],
        'status' => ['status', 'stage', 'statut', 'lead status'],
        'owner' => ['owner', 'assigned to', 'sales rep', 'responsable', 'agent'],
        'rating' => ['rating', 'temperature', 'priority'],
        'estimated_value' => ['estimated value', 'value', 'amount', 'budget', 'deal value'],
        'tags' => ['tags', 'labels', 'tag'],
        'description' => ['description', 'notes', 'note', 'comments', 'remarks'],
    ];

    public function __construct(private CrmLeadService $service)
    {
    }

    public function template(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'importExport', CrmLead::class);
        $headers = ['First name', 'Last name', 'Company', 'Job title', 'Email', 'Phone', 'City', 'Country', 'Source', 'Status', 'Owner', 'Rating', 'Estimated value', 'Tags', 'Description'];
        $sample = ['Sara', 'El Amrani', 'Atlas Trading', 'Purchasing manager', 'sara@example.com', '+212 600 000 000', 'Casablanca', 'Morocco', 'Website', 'New', '', 'hot', '15000', 'VIP, Wholesale', 'Asked for a catalogue'];

        return response()->streamDownload(function () use ($headers, $sample) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            fputcsv($out, $sample);
            fclose($out);
        }, 'leads_import_template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'importExport', CrmLead::class);
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);

        [$headers, $rows] = $this->read($request->file('file')->getRealPath());
        if (! $headers) {
            return response()->json(['message' => 'The file is empty or not a CSV file.'], 422);
        }

        return response()->json([
            'headers' => $headers,
            'sample' => array_slice($rows, 0, 5),
            'total' => count($rows),
            'mapping' => $this->suggest($headers),
            'fields' => array_keys(self::FIELDS),
            'max_rows' => self::MAX_ROWS,
        ]);
    }

    public function import(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'importExport', CrmLead::class);
        $this->authorizeForUser($user, 'create', CrmLead::class);
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
            'mapping' => 'required|string',
            'skip_duplicates' => 'nullable|boolean',
            'default_owner_id' => 'nullable|integer',
            'default_source_id' => 'nullable|integer|exists:crm_lead_sources,id',
            'create_sources' => 'nullable|boolean',
        ]);

        $mapping = json_decode($request->mapping, true);
        if (! is_array($mapping)) {
            return response()->json(['message' => 'The column mapping could not be read.'], 422);
        }
        $mapping = array_filter($mapping, fn ($col, $field) => isset(self::FIELDS[$field]) && is_numeric($col), ARRAY_FILTER_USE_BOTH);
        if (! array_intersect(array_keys($mapping), ['full_name', 'first_name', 'last_name', 'company_name'])) {
            return response()->json(['message' => 'Map at least one name column (name, first / last name or company).'], 422);
        }

        [, $rows] = $this->read($request->file('file')->getRealPath());
        if (count($rows) > self::MAX_ROWS) {
            return response()->json(['message' => 'At most ' . self::MAX_ROWS . ' rows can be imported at once. Split the file.'], 422);
        }

        $viewAll = $user->can('viewAll', CrmLead::class);
        $defaultOwner = $request->default_owner_id ? (int) $request->default_owner_id : null;
        if ($defaultOwner && ! $viewAll && $defaultOwner !== (int) $user->id) {
            $defaultOwner = $user->id;
        }
        if (! $defaultOwner && CrmSetting::current()->default_owner === 'creator') {
            $defaultOwner = $user->id;
        }
        $useRules = CrmSetting::current()->default_owner === 'rules';
        $skipDuplicates = $request->boolean('skip_duplicates', true);
        $createSources = $request->boolean('create_sources', true);

        $sources = CrmLeadSource::get()->keyBy(fn ($s) => mb_strtolower($s->name));
        $statuses = CrmLeadStatus::where('kind', 'open')->get()->keyBy(fn ($s) => mb_strtolower($s->name));
        $defaultStatus = CrmLeadStatus::defaultId();
        $users = User::whereNull('deleted_at')->get(['id', 'firstname', 'lastname', 'username', 'email']);

        $created = 0;
        $skipped = 0;
        $errors = [];
        $seenEmails = [];
        $seenPhones = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2; // + header row, 1-based
            $v = fn ($field) => isset($mapping[$field]) ? trim((string) ($row[(int) $mapping[$field]] ?? '')) : '';

            $first = $v('first_name');
            $last = $v('last_name');
            if ($first === '' && $last === '' && $v('full_name') !== '') {
                $parts = preg_split('/\s+/', $v('full_name'), 2);
                $first = $parts[0];
                $last = $parts[1] ?? '';
            }
            $company = $v('company_name');
            $type = ($first === '' && $last === '' && $company !== '') ? 'company' : 'person';
            $name = CrmLead::displayName(['type' => $type, 'first_name' => $first, 'last_name' => $last, 'company_name' => $company]);
            if ($name === '') {
                if (count(array_filter($row, fn ($c) => trim((string) $c) !== ''))) {
                    $errors[] = ['row' => $line, 'message' => 'No name'];
                }
                continue;
            }

            $email = mb_strtolower($v('email'));
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['row' => $line, 'message' => "Invalid email \"{$email}\" (kept empty)"];
                $email = '';
            }
            $phone = $v('phone');
            $digits = CrmLead::normalizePhone($phone);

            if ($skipDuplicates) {
                $dupInFile = ($email !== '' && isset($seenEmails[$email])) || ($digits && isset($seenPhones[$digits]));
                $dup = $this->service->duplicates($email, $phone);
                if ($dupInFile || $dup['leads'] || $dup['clients']) {
                    $skipped++;
                    continue;
                }
            }

            $sourceId = $request->default_source_id ? (int) $request->default_source_id : null;
            $sourceName = $v('source');
            if ($sourceName !== '') {
                $key = mb_strtolower($sourceName);
                if (! isset($sources[$key]) && $createSources) {
                    $sources[$key] = CrmLeadSource::create(['name' => mb_substr($sourceName, 0, 120), 'sort_order' => 100]);
                }
                $sourceId = isset($sources[$key]) ? $sources[$key]->id : $sourceId;
            }
            $statusName = mb_strtolower($v('status'));
            $statusId = $statusName !== '' && isset($statuses[$statusName]) ? $statuses[$statusName]->id : $defaultStatus;

            $ownerId = $defaultOwner;
            if (($o = mb_strtolower($v('owner'))) !== '') {
                $match = $users->first(fn ($u) => mb_strtolower((string) $u->email) === $o || mb_strtolower((string) $u->username) === $o
                    || mb_strtolower(trim($u->firstname . ' ' . $u->lastname)) === $o);
                if ($match && ($viewAll || (int) $match->id === (int) $user->id)) {
                    $ownerId = $match->id;
                }
            }

            $rating = mb_strtolower($v('rating'));
            $value = (float) str_replace([' ', ','], ['', '.'], preg_replace('/[^\d,.\s-]/', '', $v('estimated_value')));

            // No owner in the file or the form: the assignment rules decide (when CRM Settings say so).
            if (! $ownerId && $useRules) {
                $probe = new CrmLead(['country' => $v('country') ?: null, 'crm_lead_source_id' => $sourceId, 'estimated_value' => max(0, $value)]);
                [$ownerId] = app(\App\Services\Crm\CrmAssignmentService::class)->pick($probe, 'import');
            }

            try {
                DB::transaction(function () use ($type, $name, $first, $last, $company, $v, $email, $phone, $digits, $sourceId, $statusId, $ownerId, $rating, $value, $user) {
                    $lead = CrmLead::create([
                        'lead_number' => $this->service->nextNumber(),
                        'type' => $type,
                        'name' => mb_substr($name, 0, 191),
                        'first_name' => mb_substr($first, 0, 100) ?: null,
                        'last_name' => mb_substr($last, 0, 100) ?: null,
                        'company_name' => mb_substr($company, 0, 191) ?: null,
                        'job_title' => mb_substr($v('job_title'), 0, 120) ?: null,
                        'email' => $email ?: null,
                        'phone' => mb_substr($phone, 0, 50) ?: null,
                        'phone_normalized' => $digits,
                        'website' => mb_substr($v('website'), 0, 191) ?: null,
                        'industry' => mb_substr($v('industry'), 0, 120) ?: null,
                        'address' => mb_substr($v('address'), 0, 255) ?: null,
                        'city' => mb_substr($v('city'), 0, 120) ?: null,
                        'country' => mb_substr($v('country'), 0, 120) ?: null,
                        'crm_lead_source_id' => $sourceId,
                        'crm_lead_status_id' => $statusId,
                        'owner_id' => $ownerId,
                        'rating' => in_array($rating, CrmLead::RATINGS, true) ? $rating : 'warm',
                        'estimated_value' => max(0, round($value, 2)),
                        'description' => $v('description') ?: null,
                        'captured_via' => 'import',
                        'last_activity_at' => now(),
                        'created_by' => $user->id,
                    ]);
                    if (($tags = $v('tags')) !== '') {
                        $this->service->syncTags($lead, array_slice(array_map('trim', preg_split('/[,;|]/', $tags)), 0, 20));
                    }
                    $this->service->log($lead, 'imported');
                });
                $created++;
                if ($email !== '') {
                    $seenEmails[$email] = true;
                }
                if ($digits) {
                    $seenPhones[$digits] = true;
                }
            } catch (\Throwable $e) {
                $errors[] = ['row' => $line, 'message' => mb_substr($e->getMessage(), 0, 200)];
            }
        }

        return response()->json([
            'success' => true,
            'created' => $created,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 200),
            'error_count' => count($errors),
        ]);
    }

    /** [headers, rows] from a CSV, delimiter sniffed from the first line. */
    private function read(string $path): array
    {
        $fh = fopen($path, 'r');
        if (! $fh) {
            return [[], []];
        }
        $first = (string) fgets($fh);
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delimiter = collect([',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")])
            ->sortDesc()->keys()->first();
        rewind($fh);

        $headers = [];
        $rows = [];
        while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
            if (! $headers) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($row[0] ?? ''));
                $headers = array_map(fn ($h) => trim((string) $h), $row);
                continue;
            }
            if (count(array_filter($row, fn ($c) => $c !== null && trim((string) $c) !== '')) === 0) {
                continue;
            }
            $rows[] = array_map(fn ($c) => $this->utf8((string) $c), $row);
            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }
        fclose($fh);

        return [array_map(fn ($h) => $this->utf8($h), $headers), $rows];
    }

    private function utf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    /** field => column index, matched on normalised header names. */
    private function suggest(array $headers): array
    {
        // Headers and aliases go through the same normalisation ("E-mail" = "e mail" = "email").
        $norm = fn ($h) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}0-9 ]+/u', ' ', mb_strtolower($h))));
        $squash = fn ($h) => str_replace(' ', '', $norm($h));
        $out = [];
        foreach ($headers as $i => $h) {
            $n = $squash($h);
            foreach (self::FIELDS as $field => $aliases) {
                $names = array_map($squash, array_merge([str_replace('_', ' ', $field)], $aliases));
                if (! isset($out[$field]) && in_array($n, $names, true)) {
                    $out[$field] = $i;
                    break;
                }
            }
        }

        return $out;
    }
}
