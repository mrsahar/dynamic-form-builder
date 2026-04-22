<?php
if (!defined('ABSPATH')) exit;

/**
 * Load plugin translations from /languages.
 *
 * @return void
 */
function dfb_load_textdomain() {
    load_plugin_textdomain(
        'dynamic-form-builder',
        false,
        dirname(plugin_basename(DFB_PLUGIN_DIR . 'dynamic-form-builder.php')) . '/languages'
    );
}

require_once DFB_PLUGIN_DIR . 'includes/translations-fi.php';

/**
 * Register a string with common multilingual plugins (best-effort).
 *
 * @param string $string
 * @param string $name    Stable identifier, e.g. "form_12_q_3_title"
 * @param string $context Group, e.g. "dynamic-form-builder"
 * @return void
 */
function dfb_register_i18n_string($string, $name, $context = 'dynamic-form-builder') {
    $string = (string) $string;
    $name   = (string) $name;
    $context = (string) $context;

    if ($string === '' || $name === '') {
        return;
    }

    // WPML String Translation.
    if (has_action('wpml_register_single_string')) {
        do_action('wpml_register_single_string', $context, $name, $string);
    }

    // Polylang.
    if (function_exists('pll_register_string')) {
        pll_register_string($name, $string, $context);
    }
}

/**
 * Translate a stored (DB) string using multilingual plugins when available.
 * Falls back to the raw string when no translation exists.
 *
 * @param string $string
 * @param string $name    Stable identifier, e.g. "form_12_q_3_title"
 * @param string $context Group, e.g. "dynamic-form-builder"
 * @return string
 */
function dfb_translate_i18n_string($string, $name, $context = 'dynamic-form-builder') {
    $string = (string) $string;
    $name   = (string) $name;
    $context = (string) $context;

    if ($string === '') {
        return '';
    }

    // Allow custom integrations.
    $custom = apply_filters('dfb_translate_string', $string, $name, $context);
    if (is_string($custom) && $custom !== '' && $custom !== $string) {
        return $custom;
    }

    // WPML.
    if (has_filter('wpml_translate_single_string')) {
        $t = apply_filters('wpml_translate_single_string', $string, $context, $name);
        if (is_string($t) && $t !== '') {
            return $t;
        }
    }

    // Polylang: translates by original string value (must be registered).
    if (function_exists('pll__')) {
        $t = pll__($string);
        if (is_string($t) && $t !== '') {
            return $t;
        }
    }

    return $string;
}

