<?php

/*
 * Angola AGT — API "Facturação Electrónica" (schema 2.0), per the official
 * documentation at portaldoparceiro.minfin.gov.ao/doc-agt/faturacao-electronica.
 * The service is asynchronous: registarFactura only returns a requestID,
 * the verdict is fetched later with obterEstado (see agt:poll).
 */
return [

    'schema_version' => '2.0',

    'environments' => [
        'test' => [
            'label' => 'Homologação (testes)',
            'base_url' => 'https://sifphml.minfin.gov.ao/sigt/fe/v1',
            'partner_portal' => 'https://portaldoparceiro.hml.minfin.gov.ao/',
        ],
        'production' => [
            'label' => 'Produção',
            'base_url' => 'https://sifp.minfin.gov.ao/sigt/fe/v1',
            'partner_portal' => 'https://portaldoparceiro.minfin.gov.ao/',
        ],
    ],

    // HTTP timeout (seconds). The POS waits on this when the queue is sync.
    'timeout' => 20,

    // Max documents per registarFactura call (documented limit: 30).
    'batch_size' => 30,

    // Documents not validated after this many hours are flagged: AGT marks
    // invoices sent more than 24h late (outside contingency) with penalty.
    'late_after_hours' => 24,

    // Document types Stocky emits.
    'document_types' => [
        'FT' => 'Factura',
        'FR' => 'Factura/Recibo',
        'NC' => 'Nota de Crédito',
    ],

    // IVA tax codes (taxType = IVA).
    'tax_codes' => [
        'NOR' => 'Taxa normal',
        'INT' => 'Taxa intermédia',
        'RED' => 'Taxa reduzida',
        'ISE' => 'Isento',
        'OUT' => 'Outra',
    ],

    // obterEstado resultCode meanings.
    'result_codes' => [
        0 => 'Processamento concluído, sem facturas inválidas',
        1 => 'Processamento concluído, com facturas válidas e inválidas',
        2 => 'Processamento concluído, sem facturas válidas',
        7 => 'Pedido prematuro ou repetitivo — aguardar',
        8 => 'Processamento ainda em curso',
        9 => 'Processamento cancelado',
    ],
];
