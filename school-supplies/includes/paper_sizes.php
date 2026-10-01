<?php
// Paper for the report PDF.
// 'sizes': group => [key => [label, width mm, height mm (portrait)]]. The first size is the default.
//          To offer another size, add one line; the report layout adapts to whatever width it gets.
// 'units': mm per unit, for the custom size.
// 'limits': shortest and longest side a custom size may have, in mm. Narrower and the table gets cramped; bigger and the text gets lost.
return [
    'sizes' => [
        'Common' => [
            'a4'     => ['A4 (210 × 297 mm)', 210, 297],
            'letter' => ['Letter / Short bond (8.5 × 11 in)', 215.9, 279.4],
            'long'   => ['Long bond / Folio (8.5 × 13 in)', 215.9, 330.2],
            'legal'  => ['Legal (8.5 × 14 in)', 215.9, 355.6],
        ],
        'A and B series' => [
            'a3'    => ['A3 (297 × 420 mm)', 297, 420],
            'a5'    => ['A5 (148 × 210 mm)', 148, 210],
            'b4'    => ['B4 (250 × 353 mm)', 250, 353],
            'b5'    => ['B5 (176 × 250 mm)', 176, 250],
            'jisb4' => ['JIS B4 (257 × 364 mm)', 257, 364],
            'jisb5' => ['JIS B5 (182 × 257 mm)', 182, 257],
        ],
        'North American' => [
            'tabloid'   => ['Tabloid / Ledger (11 × 17 in)', 279.4, 431.8],
            'executive' => ['Executive (7.25 × 10.5 in)', 184.2, 266.7],
            'statement' => ['Statement / Half letter (5.5 × 8.5 in)', 139.7, 215.9],
            'govletter' => ['Government letter (8 × 10.5 in)', 203.2, 266.7],
            'quarto'    => ['Quarto (8 × 10 in)', 203.2, 254],
            'foolscap'  => ['Foolscap (8 × 13 in)', 203.2, 330.2],
            'archa'     => ['Arch A (9 × 12 in)', 228.6, 304.8],
        ],
        'Other' => [
            'f4'    => ['F4 (210 × 330 mm)', 210, 330],
            'c4'    => ['C4 (229 × 324 mm)', 229, 324],
            'c5'    => ['C5 (162 × 229 mm)', 162, 229],
            'ra4'   => ['RA4 (215 × 305 mm)', 215, 305],
            'sra4'  => ['SRA4 (225 × 320 mm)', 225, 320],
            'ra3'   => ['RA3 (305 × 430 mm)', 305, 430],
            'sra3'  => ['SRA3 (320 × 450 mm)', 320, 450],
        ],
    ],
    'units' => ['in' => 25.4, 'cm' => 10, 'mm' => 1, 'pt' => 25.4 / 72, 'pc' => 25.4 / 6],
    'limits' => [130, 450],
];
