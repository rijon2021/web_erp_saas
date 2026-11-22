<?php

// app/Exports/StockExport.php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Count Stock sheet. Code first, because names are not unique; the blank
 * Counted column is filled in on the shop floor and the same file is uploaded
 * back to Count Stock (ProductsController::upload_counted_stock).
 */
class StockExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function collection()
    {
        return collect($this->data);
    }

    public function headings(): array
    {
        return [
            'Code',
            'Product Name',
            'Category',
            'Quantity',
            'Counted',
        ];
    }

    public function map($row): array
    {
        return [
            $row['code'],
            $row['product_name'],
            $row['category'],
            $row['quantity'],
            '',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Codes stay text (leading zeros, long numeric codes); the quantity is
        // text as before so a zero still prints as "0".
        $sheet->getStyle('A')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('D')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
