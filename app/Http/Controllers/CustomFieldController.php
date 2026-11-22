<?php

namespace App\Http\Controllers;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CustomFieldController extends BaseController
{
    private $hasSortOrder = null;

    /**
     * Get all custom fields for a specific entity type
     * GET /custom-fields?entity_type=client|provider
     */
    public function index(Request $request)
    {
        $entityType = $request->input('entity_type'); // 'client' or 'provider'
        
        $query = CustomField::where('deleted_at', '=', null);
        
        if ($entityType) {
            $query->where('entity_type', $entityType);
        }
        
        if ($this->hasSortOrder()) {
            $query->orderBy('sort_order', 'asc');
        }

        $customFields = $query->orderBy('id', 'asc')
            ->get();
        
        return response()->json([
            'custom_fields' => $customFields,
        ]);
    }

    /**
     * Store a newly created custom field
     * POST /custom-fields
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'field_type' => 'required|in:text,number,textarea,date,select,checkbox',
            'entity_type' => 'required|in:client,provider',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'default_value' => 'nullable|string', // For select: JSON array string
            'sort_order' => 'nullable|integer|min:0',
            'options' => 'nullable|array',
            'options.*' => 'nullable|string|max:255',
        ]);

        // Select options may arrive as options[] (Vue 3 settings page); they are
        // stored in default_value, where the customer/supplier forms read them.
        if ($validated['field_type'] === 'select' && $request->has('options')) {
            $validated['default_value'] = $this->normalizeOptions($request->input('options'));
        }

        // If field_type is select, validate default_value as JSON array
        if ($validated['field_type'] === 'select' && is_string($validated['default_value'] ?? null) && $validated['default_value'] !== '') {
            $options = json_decode($validated['default_value'], true);
            if (!is_array($options)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Default value for select field must be a valid JSON array',
                ], 422);
            }
        }

        $attributes = [
            'name' => $validated['name'],
            'field_type' => $validated['field_type'],
            'entity_type' => $validated['entity_type'],
            'is_required' => $request->input('is_required', false),
            'is_active' => $request->input('is_active', true),
            'default_value' => $validated['default_value'] ?? null,
        ];
        if ($this->hasSortOrder()) {
            $attributes['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        }

        $customField = CustomField::create($attributes);

        return response()->json([
            'success' => true,
            'custom_field' => $customField,
        ]);
    }

    /**
     * Display the specified custom field
     * GET /custom-fields/{id}
     */
    public function show($id)
    {
        $customField = CustomField::where('deleted_at', '=', null)->findOrFail($id);
        
        return response()->json([
            'custom_field' => $customField,
        ]);
    }

    /**
     * Update the specified custom field
     * PUT /custom-fields/{id}
     */
    public function update(Request $request, $id)
    {
        $customField = CustomField::where('deleted_at', '=', null)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'field_type' => 'sometimes|required|in:text,number,textarea,date,select,checkbox',
            'entity_type' => 'sometimes|required|in:client,provider',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'default_value' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
            'options' => 'nullable|array',
            'options.*' => 'nullable|string|max:255',
        ]);

        $fieldType = $validated['field_type'] ?? $customField->field_type;
        unset($validated['options']);
        if (!$this->hasSortOrder()) {
            unset($validated['sort_order']);
        } elseif (array_key_exists('sort_order', $validated)) {
            $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        }
        if ($fieldType === 'select' && $request->has('options')) {
            $validated['default_value'] = $this->normalizeOptions($request->input('options'));
        }

        // If field_type is select, validate default_value as JSON array
        if ($fieldType === 'select' && is_string($validated['default_value'] ?? null) && $validated['default_value'] !== '') {
            $options = json_decode($validated['default_value'], true);
            if (!is_array($options)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Default value for select field must be a valid JSON array',
                ], 422);
            }
        }

        $customField->update($validated);

        return response()->json([
            'success' => true,
            'custom_field' => $customField,
        ]);
    }

    /**
     * Remove the specified custom field
     * DELETE /custom-fields/{id}
     */
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', \App\Models\Setting::class);
        
        $customField = CustomField::where('deleted_at', '=', null)->findOrFail($id);
        
        // Soft delete the custom field using SoftDeletes trait
        $customField->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Get custom field values for a specific entity
     * GET /custom-fields/values?entity_type=App\Models\Client&entity_id=1
     */
    public function getValues(Request $request)
    {
        $entityType = $request->input('entity_type'); // 'App\Models\Client' or 'App\Models\Provider'
        $entityId = $request->input('entity_id');

        if (!$entityType || !$entityId) {
            return response()->json([
                'success' => false,
                'message' => 'entity_type and entity_id are required',
            ], 422);
        }

        $values = CustomFieldValue::where('deleted_at', '=', null)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->with('customField')
            ->get();

        // Format as key-value pairs for easier frontend consumption
        $formattedValues = [];
        foreach ($values as $value) {
            $formattedValues[$value->custom_field_id] = [
                'id' => $value->id,
                'custom_field_id' => $value->custom_field_id,
                'value' => $value->value,
                'field' => $value->customField,
            ];
        }

        return response()->json([
            'success' => true,
            'values' => $formattedValues,
        ]);
    }

    /**
     * Save custom field values for a specific entity
     * POST /custom-fields/values
     */
    public function saveValues(Request $request)
    {
        $validated = $request->validate([
            'entity_type' => 'required|string', // 'App\Models\Client' or 'App\Models\Provider'
            'entity_id' => 'required|integer',
            'values' => 'required|array', // ['custom_field_id' => 'value', ...]
        ]);

        $entityType = $validated['entity_type'];
        $entityId = $validated['entity_id'];
        $values = $validated['values'];

        // Get all custom fields for this entity type to validate
        $entityTypeShort = $entityType === 'App\Models\Client' ? 'client' : 'provider';
        $customFields = CustomField::where('deleted_at', '=', null)
            ->where('entity_type', $entityTypeShort)
            ->get()
            ->keyBy('id');

        // Validate required fields
        foreach ($customFields as $field) {
            if ($field->is_required && !isset($values[$field->id])) {
                return response()->json([
                    'success' => false,
                    'message' => "Field '{$field->name}' is required",
                ], 422);
            }
        }

        // Save or update values
        foreach ($values as $customFieldId => $value) {
            if (!isset($customFields[$customFieldId])) {
                continue; // Skip invalid field IDs
            }

            // Handle checkbox values (convert to string)
            if ($customFields[$customFieldId]->field_type === 'checkbox') {
                $value = $value ? '1' : '0';
            } else {
                $value = (string) $value;
            }

            CustomFieldValue::updateOrCreate(
                [
                    'custom_field_id' => $customFieldId,
                    'entity_id' => $entityId,
                    'entity_type' => $entityType,
                ],
                [
                    'value' => $value,
                ]
            );
        }

        return response()->json(['success' => true]);
    }

    /**
     * custom_fields.sort_order arrives with tenant migration 2026_10_05_000002;
     * until it runs, sorting falls back to id and the value is not written.
     */
    private function hasSortOrder()
    {
        return $this->hasSortOrder ??= Schema::hasColumn('custom_fields', 'sort_order');
    }

    /**
     * Trim, de-duplicate and drop blank select options; null when none remain.
     */
    private function normalizeOptions($options)
    {
        $clean = [];
        foreach ((array) $options as $option) {
            $option = trim((string) $option);
            if ($option !== '' && !in_array($option, $clean, true)) {
                $clean[] = $option;
            }
        }

        return $clean ?: null;
    }
}
