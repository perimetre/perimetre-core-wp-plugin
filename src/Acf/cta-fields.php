<?php

declare(strict_types=1);

/**
 * ACF field helpers for CTA and CTA Group registration.
 */

/**
 * Returns ACF sub-fields for a single CTA (link + variant).
 *
 * @param string               $prefix   Field name prefix.
 * @param array<string, string> $variants Select choices for the CTA variant.
 * @return array<int, array<string, mixed>>
 */
function perimetre_cta_fields(
    string $prefix = 'cta',
    array $variants = [
        'default'   => 'Default',
        'primary'   => 'Primary',
        'secondary' => 'Secondary',
    ],
): array {
    return [
        [
            'key'           => "field_{$prefix}_link",
            'name'          => "{$prefix}_link",
            'label'         => 'Link',
            'type'          => 'link',
        ],
        [
            'key'           => "field_{$prefix}_variant",
            'name'          => "{$prefix}_variant",
            'label'         => 'Variant',
            'type'          => 'select',
            'choices'       => $variants,
            'default_value' => array_key_first($variants),
        ],
        [
            'key'             => "field_{$prefix}_icon",
            'name'            => "{$prefix}_icon",
            'label'           => 'Icon',
            'type'            => 'image',
            'return_format'   => 'id',
            'mime_types'      => 'svg,png',
            'required'        => 0,
            'show_in_graphql' => 1,
        ],
    ];
}

/**
 * Returns ACF fields for a CTA group (tab + repeater of CTAs).
 *
 * @param string               $prefix   Field name prefix.
 * @param string               $label    Human-readable label.
 * @param int                  $min      Minimum number of CTAs.
 * @param int                  $max      Maximum number of CTAs.
 * @param array<string, string> $variants Select choices for the CTA variant.
 * @return array<int, array<string, mixed>>
 */
function perimetre_cta_group_fields(
    string $prefix = 'cta_group',
    string $label = 'CTAs',
    int $min = 0,
    int $max = 3,
    array $variants = [
        'default'   => 'Default',
        'primary'   => 'Primary',
        'secondary' => 'Secondary',
    ],
): array {
    return [
        [
            'key'   => "field_{$prefix}_tab",
            'label' => $label,
            'type'  => 'tab',
        ],
        [
            'key'        => "field_{$prefix}",
            'name'       => $prefix,
            'label'      => $label,
            'type'       => 'repeater',
            'min'        => $min,
            'max'        => $max,
            'layout'     => 'block',
            'sub_fields' => perimetre_cta_fields("{$prefix}_item", $variants),
        ],
    ];
}
