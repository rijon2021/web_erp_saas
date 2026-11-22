<?php

namespace App\Support\Excel;

use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Spreadsheet formula-injection guard for every Laravel-Excel export.
 *
 * The default binder turns any string starting with "=" into a FORMULA, so a
 * product/customer name like =HYPERLINK("http://evil","click") becomes a live
 * formula in the exported workbook. Exported data is never meant to contain
 * formulas: such strings are written as plain text and display unchanged.
 */
class SafeValueBinder extends DefaultValueBinder
{
    public function bindValue(Cell $cell, $value)
    {
        if (is_string($value) && strlen($value) > 1 && $value[0] === '=') {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
