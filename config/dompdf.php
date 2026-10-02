<?php

return [
    /*
    | laravel-dompdf merges this file over its own with a top-level array_merge,
    | so an 'options' key here REPLACES the package's whole options array rather
    | than adding to it. Everything the package sets must therefore be repeated,
    | or dompdf silently falls back to its library defaults — which put font_dir
    | and font_cache inside vendor/, chroot at the dompdf lib directory (local
    | file reads outside it are refused) and default_paper_size at "letter".
    |
    | The one deliberate change is default_font: dompdf's own default is "serif",
    | which renders Arabic and other non-Latin labels as ????. "dejavu sans" is
    | bundled and has full Unicode coverage.
    */
    'options' => [
        'font_dir' => storage_path('fonts'),
        'font_cache' => storage_path('fonts'),
        'temp_dir' => sys_get_temp_dir(),
        'chroot' => realpath(base_path()),
        'allowed_protocols' => [
            'data://' => ['rules' => []],
            'file://' => ['rules' => []],
            'http://' => ['rules' => []],
            'https://' => ['rules' => []],
        ],
        'artifactPathValidation' => null,
        'log_output_file' => null,
        'enable_font_subsetting' => false,
        'pdf_backend' => 'CPDF',
        'default_media_type' => 'screen',
        'default_paper_size' => 'a4',
        'default_paper_orientation' => 'portrait',
        'default_font' => 'dejavu sans',
        'dpi' => 96,
        'enable_php' => false,
        'enable_javascript' => true,
        'enable_remote' => false,
        'allowed_remote_hosts' => null,
        'font_height_ratio' => 1.1,
        'enable_html5_parser' => true,
    ],
];
