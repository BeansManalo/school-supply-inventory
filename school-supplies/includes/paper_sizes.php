<?php
// Paper sizes for the report PDF: key => [label, width mm, height mm] (portrait). The first one is the default.
// To offer another size, add one line; the report layout adapts to whatever width it gets.
return [
    'a4'     => ['A4 (210 × 297 mm)', 210, 297],
    'letter' => ['Letter / Short bond (8.5 × 11 in)', 215.9, 279.4],
    'long'   => ['Long bond / Folio (8.5 × 13 in)', 215.9, 330.2],
    'legal'  => ['Legal (8.5 × 14 in)', 215.9, 355.6],
    'a5'     => ['A5 (148 × 210 mm)', 148, 210],
    'a3'     => ['A3 (297 × 420 mm)', 297, 420],
];
