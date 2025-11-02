<?php
/**
 * Plugin Name: A11y Widget – Module d’accessibilité (mini)
 * Description: Bouton flottant qui ouvre un module d’accessibilité avec placeholders (à brancher selon vos besoins). Shortcode: [a11y_widget]. 
 * Version: 1.4.1
 * Author: ChatGPT
 * License: GPL-2.0-or-later
 * Text Domain: a11y-widget
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'A11Y_WIDGET_VERSION', '1.4.1' );
define( 'A11Y_WIDGET_URL', plugin_dir_url( __FILE__ ) );
define( 'A11Y_WIDGET_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Retrieve inline SVG markup for a logo asset stored in the plugin.
 *
 * @param string $filename Logo filename relative to the plugin logo directory.
 *
 * @return string
 */
function a11y_widget_get_logo_svg_from_file( $filename ) {
    $filename = (string) $filename;

    if ( '' === $filename ) {
        return '';
    }

    if ( function_exists( 'trailingslashit' ) ) {
        $base_dir = trailingslashit( A11Y_WIDGET_PATH );
    } else {
        $base_dir = rtrim( A11Y_WIDGET_PATH, '/\\' ) . '/';
    }

    $path = $base_dir . 'logo/' . ltrim( $filename, '/\\' );

    if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
        return '';
    }

    $svg = file_get_contents( $path );

    if ( false === $svg ) {
        return '';
    }

    $clean_svg = preg_replace( '/<\?xml[^>]*\?>\s*/', '', $svg );

    if ( is_string( $clean_svg ) ) {
        $svg = $clean_svg;
    }

    return trim( (string) $svg );
}

/**
 * Apply a subset of CSS declarations defined in <style> blocks directly on the matching SVG nodes.
 *
 * Some environments strip or ignore embedded <style> tags in inline SVGs. By duplicating the
 * declarations as presentation attributes we make sure the logo keeps its colors even when the
 * stylesheet is discarded.
 *
 * @param DOMDocument $dom Parsed SVG document.
 * @param string      $css Raw CSS extracted from a <style> element.
 *
 * @return void
 */
function a11y_widget_apply_inline_svg_styles( DOMDocument $dom, $css ) {
    $css = (string) $css;

    if ( '' === trim( $css ) ) {
        return;
    }

    if ( ! preg_match_all( '/\.([A-Za-z0-9_-]+)\s*\{([^}]*)\}/', $css, $matches, PREG_SET_ORDER ) ) {
        return;
    }

    $attribute_map = array(
        'fill'              => 'fill',
        'stroke'            => 'stroke',
        'stroke-width'      => 'stroke-width',
        'stroke-linecap'    => 'stroke-linecap',
        'stroke-linejoin'   => 'stroke-linejoin',
        'stroke-miterlimit' => 'stroke-miterlimit',
        'stroke-dasharray'  => 'stroke-dasharray',
        'stroke-dashoffset' => 'stroke-dashoffset',
        'fill-opacity'      => 'fill-opacity',
        'stroke-opacity'    => 'stroke-opacity',
        'opacity'           => 'opacity',
        'stop-color'        => 'stop-color',
        'stop-opacity'      => 'stop-opacity',
    );

    $rules = array();

    foreach ( $matches as $rule ) {
        if ( count( $rule ) < 3 ) {
            continue;
        }

        $class_name = trim( $rule[1] );
        $block      = trim( $rule[2] );

        if ( '' === $class_name || '' === $block ) {
            continue;
        }

        $declarations = array();
        $properties   = preg_split( '/;/', $block );

        foreach ( $properties as $property ) {
            $property = trim( $property );

            if ( '' === $property ) {
                continue;
            }

            $parts = explode( ':', $property, 2 );

            if ( 2 !== count( $parts ) ) {
                continue;
            }

            $name  = strtolower( trim( $parts[0] ) );
            $value = trim( $parts[1] );

            if ( '' === $value || ! isset( $attribute_map[ $name ] ) ) {
                continue;
            }

            $attribute = $attribute_map[ $name ];
            $declarations[ $attribute ] = $value;
        }

        if ( empty( $declarations ) ) {
            continue;
        }

        $rules[ $class_name ] = $declarations;
    }

    if ( empty( $rules ) ) {
        return;
    }

    foreach ( $dom->getElementsByTagName( '*' ) as $node ) {
        if ( ! $node instanceof DOMElement ) {
            continue;
        }

        if ( ! $node->hasAttribute( 'class' ) ) {
            continue;
        }

        $class_value = trim( $node->getAttribute( 'class' ) );

        if ( '' === $class_value ) {
            continue;
        }

        $class_names = preg_split( '/\s+/', $class_value );

        foreach ( (array) $class_names as $candidate ) {
            $candidate = trim( (string) $candidate );

            if ( '' === $candidate || ! isset( $rules[ $candidate ] ) ) {
                continue;
            }

            foreach ( $rules[ $candidate ] as $attribute => $value ) {
                if ( $node->hasAttribute( $attribute ) && '' !== trim( $node->getAttribute( $attribute ) ) ) {
                    continue;
                }

                $node->setAttribute( $attribute, $value );
            }
        }
    }
}

/**
 * Convert raw SVG markup to a data URI that can be used in an <img> tag.
 *
 * @param string $svg Raw SVG markup.
 *
 * @return string Data URI or empty string on failure.
 */
function a11y_widget_svg_markup_to_data_uri( $svg ) {
    $svg = trim( (string) $svg );

    if ( '' === $svg ) {
        return '';
    }

    if ( function_exists( 'mb_convert_encoding' ) ) {
        $encoded = @mb_convert_encoding( $svg, 'UTF-8', 'UTF-8' );
        if ( false !== $encoded && '' !== $encoded ) {
            $svg = $encoded;
        }
    }

    $base64 = base64_encode( $svg );

    if ( false === $base64 || '' === $base64 ) {
        return '';
    }

    return 'data:image/svg+xml;base64,' . $base64;
}

/**
 * Scope the IDs defined inside an SVG fragment to prevent collisions.
 *
 * When several inline SVGs reuse the same <defs> identifiers (gradients,
 * masks, etc.), only the first definition remains effective in the DOM.
 * This helper rewrites those identifiers so each fragment stays isolated.
 *
 * @param string $svg   Raw SVG markup.
 * @param string $scope Identifier prefix to apply to every ID.
 *
 * @return string
 */
function a11y_widget_scope_logo_svg_ids( $svg, $scope ) {
    $svg   = (string) $svg;
    $scope = (string) $scope;

    if ( '' === $svg ) {
        return '';
    }

    if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
        return $svg;
    }

    if ( function_exists( 'sanitize_key' ) ) {
        $scope = sanitize_key( $scope );
    } else {
        $scope = strtolower( preg_replace( '/[^a-zA-Z0-9_-]+/', '-', $scope ) );
    }

    if ( '' === $scope ) {
        $scope = 'a11y-logo';
    }

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput       = false;

    $previous_state = libxml_use_internal_errors( true );
    $loaded         = $dom->loadXML( $svg );
    libxml_clear_errors();
    libxml_use_internal_errors( $previous_state );

    if ( ! $loaded || ! $dom->documentElement ) {
        return $svg;
    }

    $xpath = new DOMXPath( $dom );
    $nodes = $xpath->query( '//*[@id]' );

    if ( ! $nodes || 0 === $nodes->length ) {
        return $svg;
    }

    $map = array();

    foreach ( $nodes as $index => $node ) {
        if ( ! $node instanceof DOMElement ) {
            continue;
        }

        $original_id = $node->getAttribute( 'id' );

        if ( '' === $original_id ) {
            continue;
        }

        if ( function_exists( 'sanitize_key' ) ) {
            $normalized = sanitize_key( $original_id );
        } else {
            $normalized = strtolower( preg_replace( '/[^a-zA-Z0-9_-]+/', '-', $original_id ) );
        }

        if ( '' === $normalized ) {
            $normalized = 'fragment-' . ( $index + 1 );
        }

        $new_id = $scope . '-' . $normalized;

        $node->setAttribute( 'id', $new_id );
        $map[ $original_id ] = $new_id;
    }

    if ( empty( $map ) ) {
        return $svg;
    }

    $url_attributes = array(
        'fill',
        'stroke',
        'clip-path',
        'mask',
        'filter',
        'style',
        'marker-start',
        'marker-mid',
        'marker-end',
    );

    foreach ( $dom->getElementsByTagName( '*' ) as $element ) {
        if ( ! $element->hasAttributes() ) {
            continue;
        }

        $attributes = array();

        foreach ( $element->attributes as $attr ) {
            $attributes[] = $attr;
        }

        foreach ( $attributes as $attr ) {
            $name  = $attr->nodeName;
            $value = $attr->nodeValue;

            if ( '' === $value ) {
                continue;
            }

            $updated = $value;

            if ( 'aria-labelledby' === $name || 'aria-describedby' === $name ) {
                $parts = preg_split( '/[[:space:]]+/', trim( $value ) );
                if ( ! empty( $parts ) ) {
                    foreach ( $parts as $idx => $part ) {
                        if ( isset( $map[ $part ] ) ) {
                            $parts[ $idx ] = $map[ $part ];
                        }
                    }
                    $updated = implode( ' ', array_filter( $parts ) );
                }
            } elseif ( 'href' === $name || 'xlink:href' === $name ) {
                foreach ( $map as $from => $to ) {
                    if ( 0 === strpos( $updated, '#' . $from ) ) {
                        $updated = '#' . $to . substr( $updated, strlen( '#' . $from ) );
                        break;
                    }
                }
            } else {
                $needs_url_replacement = in_array( $name, $url_attributes, true ) || false !== strpos( $updated, 'url(#' );

                if ( $needs_url_replacement ) {
                    foreach ( $map as $from => $to ) {
                        if ( false === strpos( $updated, $from ) ) {
                            continue;
                        }

                        $double_quote = '"';
                        $single_quote = "'";

                        $updated = str_replace(
                            array(
                                'url(#' . $from . ')',
                                'url(' . $double_quote . '#' . $from . $double_quote . ')',
                                'url(' . $single_quote . '#' . $from . $single_quote . ')',
                            ),
                            array(
                                'url(#' . $to . ')',
                                'url(' . $double_quote . '#' . $to . $double_quote . ')',
                                'url(' . $single_quote . '#' . $to . $single_quote . ')',
                            ),
                            $updated
                        );
                    }
                }
            }

            if ( $updated !== $value ) {
                $attr->nodeValue = $updated;
            }
        }
    }

    $style_nodes = $dom->getElementsByTagName( 'style' );

    if ( $style_nodes && $style_nodes->length > 0 ) {
        foreach ( $style_nodes as $style_node ) {
            if ( ! $style_node->firstChild ) {
                continue;
            }

            $css_content = $style_node->textContent;

            if ( '' === $css_content ) {
                continue;
            }

            $final_css = $css_content;

            if ( ! empty( $map ) ) {
                $updated_css = $css_content;

                foreach ( $map as $from => $to ) {
                    $updated_css = str_replace(
                        array(
                            'url(#' . $from . ')',
                            'url("#' . $from . '")',
                            "url('#" . $from . "')",
                        ),
                        array(
                            'url(#' . $to . ')',
                            'url("#' . $to . '")',
                            "url('#" . $to . "')",
                        ),
                        $updated_css
                    );
                }

                if ( $updated_css !== $css_content ) {
                    while ( $style_node->firstChild ) {
                        $style_node->removeChild( $style_node->firstChild );
                    }

                    $style_node->appendChild( $dom->createTextNode( $updated_css ) );
                    $final_css = $updated_css;
                }
            }

            a11y_widget_apply_inline_svg_styles( $dom, $final_css );
        }
    }

    return $dom->saveXML( $dom->documentElement );
}

/**
 * Prepare SVG markup so that identifiers remain unique within the DOM.
 *
 * @param string      $svg     Raw SVG markup.
 * @param string      $slug    Logo slug used as part of the scope.
 * @param string|null $context Optional context identifier appended to the scope.
 *
 * @return string
 */
function a11y_widget_prepare_logo_svg_markup( $svg, $slug, $context = null ) {
    $svg  = (string) $svg;
    $slug = (string) $slug;

    if ( '' === $svg ) {
        return '';
    }

    if ( function_exists( 'sanitize_key' ) ) {
        $slug = sanitize_key( $slug );
    } else {
        $slug = strtolower( preg_replace( '/[^a-zA-Z0-9_-]+/', '-', $slug ) );
    }

    $parts = array( 'a11y', 'logo' );

    if ( '' !== $slug ) {
        $parts[] = $slug;
    }

    if ( null !== $context && '' !== $context ) {
        if ( function_exists( 'sanitize_key' ) ) {
            $context = sanitize_key( $context );
        } else {
            $context = strtolower( preg_replace( '/[^a-zA-Z0-9_-]+/', '-', $context ) );
        }

        if ( '' !== $context ) {
            $parts[] = $context;
        }
    }

    $base_scope = implode( '-', array_filter( $parts ) );

    if ( '' === $base_scope ) {
        $base_scope = 'a11y-logo';
    }

    if ( function_exists( 'wp_unique_id' ) ) {
        $scope = wp_unique_id( $base_scope . '-' );
    } else {
        $scope = $base_scope . '-' . uniqid( '', false );
    }

    return a11y_widget_scope_logo_svg_ids( $svg, $scope );
}

/**
 * Retrieve the available launcher logo variants.
 *
 * @return array<string, array{label:string, svg:string}>
 */
function a11y_widget_get_launcher_logo_variants() {
    static $variants = null;

    if ( null !== $variants ) {
        return $variants;
    }

    $path_markup = '<path fill="#ffffff" d="M12 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm6.75 6.5h-4.5v11a1 1 0 1 1-2 0v-5h-1v5a1 1 0 1 1-2 0v-11h-4.5a1 1 0 1 1 0-2h14a1 1 0 1 1 0 2Z" />';

    $variants = array(
        'bleu-vert' => array(
            'label' => __( 'Bleu-vert', 'a11y-widget' ),
            'svg'   => '<svg viewBox="0 0 24 24" role="img" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="12" fill="#0ea5e9" />' . $path_markup . '</svg>',
        ),
        'bleu'      => array(
            'label' => __( 'Bleu', 'a11y-widget' ),
            'svg'   => '<svg viewBox="0 0 24 24" role="img" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="12" fill="#2563eb" />' . $path_markup . '</svg>',
        ),
        'orange'    => array(
            'label' => __( 'Orange', 'a11y-widget' ),
            'svg'   => '<svg viewBox="0 0 24 24" role="img" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="12" fill="#f97316" />' . $path_markup . '</svg>',
        ),
        'rouge'     => array(
            'label' => __( 'Rouge', 'a11y-widget' ),
            'svg'   => '<svg viewBox="0 0 24 24" role="img" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="12" fill="#dc2626" />' . $path_markup . '</svg>',
        ),
        'vert'      => array(
            'label' => __( 'Vert', 'a11y-widget' ),
            'svg'   => '<svg viewBox="0 0 24 24" role="img" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="12" fill="#22c55e" />' . $path_markup . '</svg>',
        ),
    );

    $logo_files = array(
        'bleu-vert' => 'logo_bleu-vert.svg',
        'bleu'      => 'logo_bleu.svg',
        'orange'    => 'logo_orange.svg',
        'rouge'     => 'logo_rouge.svg',
        'vert'      => 'logo_vert.svg',
    );

    foreach ( $logo_files as $slug => $filename ) {
        $file_markup = a11y_widget_get_logo_svg_from_file( $filename );

        if ( '' === $file_markup ) {
            continue;
        }

        if ( ! isset( $variants[ $slug ] ) || ! is_array( $variants[ $slug ] ) ) {
            $variants[ $slug ] = array();
        }

        $variants[ $slug ]['svg'] = $file_markup;
    }

    $variants = apply_filters( 'a11y_widget_launcher_logo_variants', $variants );

    if ( ! is_array( $variants ) ) {
        $variants = array();
    }

    $sanitized = array();

    foreach ( $variants as $slug => $data ) {
        if ( is_array( $data ) && isset( $data['slug'] ) ) {
            $slug = $data['slug'];
        }

        $slug = sanitize_key( (string) $slug );

        if ( '' === $slug ) {
            continue;
        }

        $label = '';
        $svg   = '';

        if ( is_array( $data ) ) {
            if ( isset( $data['label'] ) ) {
                $label = (string) $data['label'];
            }

            if ( isset( $data['svg'] ) ) {
                $svg = (string) $data['svg'];
            }
        } elseif ( is_string( $data ) ) {
            $svg = $data;
        }

        if ( '' === $svg ) {
            continue;
        }

        $sanitized[ $slug ] = array(
            'label' => $label,
            'svg'   => $svg,
        );
    }

    $variants = $sanitized;

    return $variants;
}

/**
 * Retrieve the default launcher logo slug.
 *
 * @return string
 */
function a11y_widget_get_launcher_logo_default() {
    return 'rouge';
}

/**
 * Helper that returns the option name used to store the selected launcher logo.
 *
 * @return string
 */
function a11y_widget_get_launcher_logo_option_name() {
    return 'a11y_widget_launcher_logo';
}

/**
 * Sanitize the launcher logo slug.
 *
 * @param mixed $value Raw value.
 *
 * @return string
 */
function a11y_widget_sanitize_launcher_logo( $value ) {
    if ( is_array( $value ) ) {
        $value = reset( $value );
    }

    $value   = sanitize_key( (string) $value );
    $choices = a11y_widget_get_launcher_logo_variants();

    if ( isset( $choices[ $value ] ) ) {
        return $value;
    }

    return a11y_widget_get_launcher_logo_default();
}

/**
 * Retrieve the sanitized launcher logo slug stored in the options table.
 *
 * @return string
 */
function a11y_widget_get_launcher_logo() {
    $option = get_option(
        a11y_widget_get_launcher_logo_option_name(),
        a11y_widget_get_launcher_logo_default()
    );

    return a11y_widget_sanitize_launcher_logo( $option );
}

/**
 * Default background mode applied when opening the accessibility panel.
 *
 * @return string
 */
function a11y_widget_get_background_mode_default() {
    return 'modal';
}

/**
 * Helper returning the option name storing the background mode value.
 *
 * @return string
 */
function a11y_widget_get_background_mode_option_name() {
    return 'a11y_widget_background_mode';
}

/**
 * Sanitize the chosen background mode.
 *
 * @param mixed $value Raw option value.
 *
 * @return string
 */
function a11y_widget_sanitize_background_mode( $value ) {
    if ( is_array( $value ) ) {
        $value = reset( $value );
    }

    $value   = sanitize_key( (string) $value );
    $choices = array( 'modal', 'interactive' );

    if ( in_array( $value, $choices, true ) ) {
        return $value;
    }

    return a11y_widget_get_background_mode_default();
}

/**
 * Retrieve the sanitized background mode stored in the database.
 *
 * @return string
 */
function a11y_widget_get_background_mode() {
    $option = get_option(
        a11y_widget_get_background_mode_option_name(),
        a11y_widget_get_background_mode_default()
    );

    return a11y_widget_sanitize_background_mode( $option );
}

/**
 * Retrieve the SVG markup for the given launcher logo.
 *
 * @param string|null $slug Logo slug. Defaults to the stored option.
 *
 * @return string
 */
function a11y_widget_get_launcher_logo_markup( $slug = null ) {
    $choices = a11y_widget_get_launcher_logo_variants();

    if ( null === $slug ) {
        $slug = a11y_widget_get_launcher_logo();
    } else {
        $slug = a11y_widget_sanitize_launcher_logo( $slug );
    }

    if ( isset( $choices[ $slug ] ) ) {
        return a11y_widget_prepare_logo_svg_markup( $choices[ $slug ]['svg'], $slug, 'launcher' );
    }

    $default = a11y_widget_get_launcher_logo_default();

    return isset( $choices[ $default ] )
        ? a11y_widget_prepare_logo_svg_markup( $choices[ $default ]['svg'], $default, 'launcher' )
        : '';
}

/**
 * Retrieve an <img> tag containing the launcher logo as a data URI.
 *
 * @param string|null $slug    Logo slug. Defaults to the stored option.
 * @param string      $context Context identifier appended to the SVG scope.
 *
 * @return string
 */
function a11y_widget_get_launcher_logo_image_markup( $slug = null, $context = 'launcher' ) {
    $choices = a11y_widget_get_launcher_logo_variants();

    if ( null === $slug ) {
        $slug = a11y_widget_get_launcher_logo();
    } else {
        $slug = a11y_widget_sanitize_launcher_logo( $slug );
    }

    $svg_markup = '';

    if ( isset( $choices[ $slug ] ) ) {
        $svg_markup = a11y_widget_prepare_logo_svg_markup( $choices[ $slug ]['svg'], $slug, $context . '-image' );
    } else {
        $default = a11y_widget_get_launcher_logo_default();

        if ( isset( $choices[ $default ] ) ) {
            $svg_markup = a11y_widget_prepare_logo_svg_markup( $choices[ $default ]['svg'], $default, $context . '-image' );
        }
    }

    if ( '' === $svg_markup ) {
        return '';
    }

    $data_uri = a11y_widget_svg_markup_to_data_uri( $svg_markup );

    if ( '' === $data_uri ) {
        return '';
    }

    $attributes = array(
        'src'         => $data_uri,
        'alt'         => '',
        'role'        => 'presentation',
        'aria-hidden' => 'true',
        'decoding'    => 'async',
        'draggable'   => 'false',
    );

    $parts = array();

    foreach ( $attributes as $name => $value ) {
        $value = (string) $value;

        if ( function_exists( 'esc_attr' ) ) {
            $value = esc_attr( $value );
        }

        $parts[] = sprintf( "%s=\"%s\"", $name, $value );
    }

    return '<img ' . implode( ' ', $parts ) . ' />';
}

/**
 * Enqueue front assets
 */
function a11y_widget_enqueue() {
    // Only load on front-end
    if ( is_admin() ) { return; }

    wp_enqueue_style(
        'a11y-widget',
        A11Y_WIDGET_URL . 'assets/widget.css',
        array(),
        A11Y_WIDGET_VERSION
    );

    wp_enqueue_script(
        'a11y-widget',
        A11Y_WIDGET_URL . 'assets/widget.js',
        array(),
        A11Y_WIDGET_VERSION,
        true
    );
}
add_action( 'wp_enqueue_scripts', 'a11y_widget_enqueue' );

/**
 * Render the widget HTML
 */
function a11y_widget_markup() {
    // Allow theme/plugins to disable automatic output
    $enable_auto = apply_filters( 'a11y_widget_enable_auto', true );
    if ( did_action('a11y_widget_printed') ) { return; } // avoid duplicates

    ob_start();
    include A11Y_WIDGET_PATH . 'templates/widget.php';
    $html = ob_get_clean();

    /**
     * Filter: change/augment the HTML before output
     */
    $html = apply_filters( 'a11y_widget_markup', $html );

    echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    do_action('a11y_widget_printed');
}

/**
 * Default widget sections definition (hierarchical: level 1 categories + level 2 placeholders).
 *
 * @return array[]
 */
function a11y_widget_get_default_sections() {
    $heading_selector = function_exists( 'a11y_widget_get_reading_guide_heading_selector' )
        ? a11y_widget_get_reading_guide_heading_selector()
        : 'main h2, main h3';
    $syllable_selector = function_exists( 'a11y_widget_get_reading_guide_syllable_selector' )
        ? a11y_widget_get_reading_guide_syllable_selector()
        : 'main p, main li';

    return array(
        array(
            'slug'     => 'vision',
            'title'    => __( 'Vision', 'a11y-widget' ),
            'icon'     => 'eye',
            'children' => array(
                array(
                    'slug'       => 'vision-daltonisme',
                    'label'      => __( 'Daltonisme', 'a11y-widget' ),
                    'aria_label' => __( 'Options pour le daltonisme', 'a11y-widget' ),
                    'children'   => array(
                        array(
                            'slug'        => 'vision-daltonisme-deuteranopie',
                            'label'       => __( 'Deutéranopie', 'a11y-widget' ),
                            'aria_label'  => __( 'Activer le mode deutéranopie', 'a11y-widget' ),
                            'placeholder' => true,
                        ),
                        array(
                            'slug'        => 'vision-daltonisme-protanopie',
                            'label'       => __( 'Protanopie', 'a11y-widget' ),
                            'aria_label'  => __( 'Activer le mode protanopie', 'a11y-widget' ),
                            'placeholder' => true,
                        ),
                        array(
                            'slug'        => 'vision-daltonisme-deuteranomalie',
                            'label'       => __( 'Deutéranomalie', 'a11y-widget' ),
                            'aria_label'  => __( 'Activer le mode deutéranomalie', 'a11y-widget' ),
                            'placeholder' => true,
                        ),
                        array(
                            'slug'        => 'vision-daltonisme-protanomalie',
                            'label'       => __( 'Protanomalie', 'a11y-widget' ),
                            'aria_label'  => __( 'Activer le mode protanomalie', 'a11y-widget' ),
                            'placeholder' => true,
                        ),
                        array(
                            'slug'        => 'vision-daltonisme-tritanopie',
                            'label'       => __( 'Tritanopie', 'a11y-widget' ),
                            'aria_label'  => __( 'Activer le mode tritanopie', 'a11y-widget' ),
                            'placeholder' => true,
                        ),
                        array(
                            'slug'        => 'vision-daltonisme-tritanomalie',
                            'label'       => __( 'Tritanomalie', 'a11y-widget' ),
                            'aria_label'  => __( 'Activer le mode tritanomalie', 'a11y-widget' ),
                            'placeholder' => true,
                        ),
                        array(
                            'slug'        => 'vision-daltonisme-achromatopsie',
                            'label'       => __( 'Achromatopsie', 'a11y-widget' ),
                            'aria_label'  => __( 'Activer le mode achromatopsie', 'a11y-widget' ),
                            'placeholder' => true,
                        ),
                    ),
                ),
                array(
                    'slug'       => 'vision-migraine',
                    'label'      => __( 'Migraines ophtalmiques', 'a11y-widget' ),
                    'hint'       => '',
                    'aria_label' => __( 'Configurer les réglages pour soulager les migraines visuelles', 'a11y-widget' ),
                    'template'   => 'migraine-relief',
                    'settings'   => array(
                        'intro'                        => '',
                        'theme_label'                  => __( 'Thème de confort visuel', 'a11y-widget' ),
                        'theme_hint'                   => '',
                        'theme_option_none'            => __( 'Standard atténué', 'a11y-widget' ),
                        'theme_option_none_aria'       => __( 'Utiliser le rendu standard atténué', 'a11y-widget' ),
                        'theme_option_grayscale'       => __( 'Tons neutres', 'a11y-widget' ),
                        'theme_option_grayscale_aria'  => __( 'Passer en niveaux de gris doux', 'a11y-widget' ),
                        'theme_option_amber'           => __( 'Filtre ambré', 'a11y-widget' ),
                        'theme_option_amber_aria'      => __( 'Activer le filtre ambré pour réduire la lumière bleue', 'a11y-widget' ),
                        'intensity_label'              => __( 'Intensité du filtre ambré', 'a11y-widget' ),
                        'intensity_hint'               => '',
                        'intensity_value_suffix'       => __( '%', 'a11y-widget' ),
                        'intensity_decrease'           => __( 'Diminuer l’intensité', 'a11y-widget' ),
                        'intensity_increase'           => __( 'Augmenter l’intensité', 'a11y-widget' ),
                        'remove_patterns_label'        => __( 'Supprimer les motifs répétitifs', 'a11y-widget' ),
                        'remove_patterns_hint'         => '',
                        'increase_spacing_label'       => __( 'Espacement augmenté', 'a11y-widget' ),
                        'increase_spacing_hint'        => '',
                        'presets_label'                => __( 'Presets rapides', 'a11y-widget' ),
                        'preset_mild_label'            => __( 'Soulagement doux', 'a11y-widget' ),
                        'preset_mild_hint'             => '',
                        'preset_moderate_label'        => __( 'Mode focus', 'a11y-widget' ),
                        'preset_moderate_hint'         => '',
                        'preset_strong_label'          => __( 'Protection renforcée', 'a11y-widget' ),
                        'preset_strong_hint'           => '',
                        'preset_crisis_label'          => __( 'Mode crise', 'a11y-widget' ),
                        'preset_crisis_hint'           => '',
                        'reset_label'                  => __( 'Réinitialiser les réglages migraine', 'a11y-widget' ),
                        'reset_aria'                   => __( 'Réinitialiser toutes les options de soulagement migraine', 'a11y-widget' ),
                        'live_region_label'            => __( 'Notification des réglages migraine', 'a11y-widget' ),
                    ),
                ),
            ),
        ),
        array(
            'slug'     => 'luminosite',
            'title'    => __( 'Luminosité', 'a11y-widget' ),
            'icon'     => 'sun',
            'children' => array(
                array(
                    'slug'       => 'luminosite-reglages',
                    'label'      => __( 'Modes de luminosité', 'a11y-widget' ),
                    'hint'       => '',
                    'aria_label' => __( 'Configurer les options de luminosité', 'a11y-widget' ),
                    'template'   => 'brightness-settings',
                    'settings'   => array(
                        'modes_label'             => __( 'Mode d’affichage', 'a11y-widget' ),
                        'modes_hint'              => '',
                        'mode_normal_label'       => __( 'Normal', 'a11y-widget' ),
                        'mode_normal_aria'        => __( 'Revenir au mode normal', 'a11y-widget' ),
                        'mode_night_label'        => __( 'Mode nuit', 'a11y-widget' ),
                        'mode_night_aria'         => __( 'Activer le mode nuit', 'a11y-widget' ),
                        'mode_blue_light_label'   => __( 'Lumière bleue', 'a11y-widget' ),
                        'mode_blue_light_aria'    => __( 'Activer le filtre anti lumière bleue', 'a11y-widget' ),
                        'mode_high_contrast_label'=> __( 'Contraste +', 'a11y-widget' ),
                        'mode_high_contrast_aria' => __( 'Activer le mode contraste élevé', 'a11y-widget' ),
                        'mode_low_contrast_label' => __( 'Contraste -', 'a11y-widget' ),
                        'mode_low_contrast_aria'  => __( 'Activer le mode contraste réduit', 'a11y-widget' ),
                        'mode_grayscale_label'    => __( 'Niveaux de gris', 'a11y-widget' ),
                        'mode_grayscale_aria'     => __( 'Activer le mode niveaux de gris', 'a11y-widget' ),
                        'advanced_label'          => __( 'Réglages avancés', 'a11y-widget' ),
                        'advanced_hint'           => '',
                        'contrast_label'          => __( 'Contraste', 'a11y-widget' ),
                        'contrast_decrease'       => __( 'Diminuer le contraste', 'a11y-widget' ),
                        'contrast_increase'       => __( 'Augmenter le contraste', 'a11y-widget' ),
                        'brightness_label'        => __( 'Luminosité', 'a11y-widget' ),
                        'brightness_decrease'     => __( 'Diminuer la luminosité', 'a11y-widget' ),
                        'brightness_increase'     => __( 'Augmenter la luminosité', 'a11y-widget' ),
                        'saturation_label'        => __( 'Saturation', 'a11y-widget' ),
                        'saturation_decrease'     => __( 'Diminuer la saturation', 'a11y-widget' ),
                        'saturation_increase'     => __( 'Augmenter la saturation', 'a11y-widget' ),
                        'reset_label'             => __( 'Réinitialiser', 'a11y-widget' ),
                        'reset_aria'              => __( 'Réinitialiser les réglages de luminosité', 'a11y-widget' ),
                    ),
                ),
            ),
        ),
        array(
            'slug'     => 'cognitif',
            'title'    => __( 'Cognitif', 'a11y-widget' ),
            'icon'     => 'brain',
            'children' => array(
                array(
                    'slug'       => 'cognitif-reading-guide',
                    'label'      => __( 'Guide de lecture', 'a11y-widget' ),
                    'hint'       => '',
                    'aria_label' => __( 'Activer le guide de lecture', 'a11y-widget' ),
                    'template'   => 'reading-guide',
                    'settings'   => array(
                        'rule_label'                     => __( 'Règle de lecture', 'a11y-widget' ),
                        'rule_hint'                      => '',
                        'personalization_label'          => __( 'Personnalisation de la règle', 'a11y-widget' ),
                        'color_label'                    => __( 'Couleur', 'a11y-widget' ),
                        'color_hint'                     => '',
                        'opacity_label'                  => __( 'Opacité', 'a11y-widget' ),
                        'opacity_hint'                   => '',
                        'height_label'                   => __( 'Taille', 'a11y-widget' ),
                        'height_hint'                    => '',
                        'summary_label'                  => __( 'Sommaire automatique', 'a11y-widget' ),
                        'summary_close_label'            => __( 'Fermer le sommaire', 'a11y-widget' ),
                        'summary_hint'                   => '',
                        'summary_toggle_label'           => __( 'Activer le sommaire', 'a11y-widget' ),
                        'summary_title_default'          => __( 'Sommaire', 'a11y-widget' ),
                        'syllable_label'                 => __( 'Séparation syllabique', 'a11y-widget' ),
                        'syllable_hint'                  => '',
                        'syllable_toggle_label'          => __( 'Activer la séparation syllabique', 'a11y-widget' ),
                        'syllable_selector_label'        => __( 'Zones à syllaber', 'a11y-widget' ),
                        'syllable_selector_hint'         => '',
                        'syllable_selector_default'      => $syllable_selector,
                        'syllable_selector_placeholder'  => __( 'Ex. article p, article li', 'a11y-widget' ),
                        'focus_label'                    => __( 'Mode focus', 'a11y-widget' ),
                        'focus_hint'                     => '',
                        'focus_toggle_label'             => __( 'Activer le mode focus', 'a11y-widget' ),
                        'selectors'                      => array(
                            'headings'                      => $heading_selector,
                            'content_attribute'             => 'data-reading-guide-content',
                            'toc_attribute'                 => 'data-reading-guide-toc',
                            'toc_title_attribute'           => 'data-reading-guide-toc-title',
                            'syllable_attribute'            => 'data-reading-guide-syllables',
                            'animation_exempt_attribute'    => 'data-reading-guide-allow-animation',
                        ),
                    ),
                ),
                array(
                    'slug'       => 'cognitif-dyslexie',
                    'label'      => __( 'Dyslexie', 'a11y-widget' ),
                    'hint'       => '',
                    'aria_label' => __( 'Activer le surligneur de lettres', 'a11y-widget' ),
                    'template'   => 'dyslexie-highlighter',
                    'settings'   => array(
                        'letter_label'        => __( 'Lettre à surligner', 'a11y-widget' ),
                        'letter_placeholder'  => __( 'Entrez une lettre', 'a11y-widget' ),
                        'color_label'         => __( 'Couleur du surlignage', 'a11y-widget' ),
                        'accent_label'        => __( 'Prendre les accents en compte', 'a11y-widget' ),
                        'no_letter_warning'   => __( 'Saisissez une lettre pour commencer le surlignage.', 'a11y-widget' ),
                        'font_label'          => __( 'Police du texte', 'a11y-widget' ),
                        'font_help'           => '',
                        'font_option_default' => __( 'Police du site', 'a11y-widget' ),
                        'font_option_arial'   => __( 'Arial', 'a11y-widget' ),
                        'font_option_verdana' => __( 'Verdana', 'a11y-widget' ),
                        'font_option_trebuchet' => __( 'Trebuchet MS', 'a11y-widget' ),
                        'font_option_comic'   => __( 'Comic Sans MS', 'a11y-widget' ),
                        'font_option_open'    => __( 'OpenDyslexic', 'a11y-widget' ),
                        'font_option_dyslexic' => __( 'OpenDyslexic Alta', 'a11y-widget' ),
                        'font_option_luciole' => __( 'Luciole', 'a11y-widget' ),
                        'font_option_atkinson' => __( 'Atkinson Hyperlegible', 'a11y-widget' ),
                        'font_option_inconstant' => __( 'Inconstant', 'a11y-widget' ),
                        'font_option_accessible_dfa' => __( 'Accessible DfA', 'a11y-widget' ),
                        'size_label'          => __( 'Taille du texte', 'a11y-widget' ),
                        'size_help'           => '',
                        'line_label'          => __( 'Espacement des lignes', 'a11y-widget' ),
                        'line_help'           => '',
                        'styles_label'        => __( 'Styles du texte', 'a11y-widget' ),
                        'styles_help'         => '',
                        'disable_italic_label'=> __( 'Supprimer l’italique', 'a11y-widget' ),
                        'disable_bold_label'  => __( 'Supprimer le gras', 'a11y-widget' ),
                        'reset_label'         => __( 'Réinitialiser les réglages du texte', 'a11y-widget' ),
                    ),
                ),
            ),
        ),
        array(
            'slug'     => 'moteur',
            'title'    => __( 'Moteur', 'a11y-widget' ),
            'icon'     => 'hand',
            'children' => array(
                array(
                    'slug'       => 'moteur-boutons',
                    'label'      => __( 'Boutons', 'a11y-widget' ),
                    'hint'       => '',
                    'aria_label' => __( 'Configurer l’apparence des boutons', 'a11y-widget' ),
                    'template'   => 'button-settings',
                    'settings'   => array(
                        'size_label'  => __( 'Taille des boutons', 'a11y-widget' ),
                        'size_help'   => '',
                        'theme_label' => __( 'Couleurs des boutons', 'a11y-widget' ),
                        'theme_help'  => '',
                        'theme_prev'  => __( 'Thème précédent', 'a11y-widget' ),
                        'theme_next'  => __( 'Thème suivant', 'a11y-widget' ),
                        'reset_label' => __( 'Réinitialiser les boutons', 'a11y-widget' ),
                    ),
                ),
                array(
                    'slug'       => 'moteur-curseur',
                    'label'      => __( 'Curseur', 'a11y-widget' ),
                    'hint'       => '',
                    'aria_label' => __( 'Configurer le curseur personnalisé', 'a11y-widget' ),
                    'template'   => 'cursor-settings',
                    'settings'   => array(
                        'size_label' => __( 'Taille du curseur', 'a11y-widget' ),
                        'size_help'  => '',
                        'color_label' => __( 'Couleur du curseur', 'a11y-widget' ),
                        'color_help'  => '',
                    ),
                ),
            ),
        ),
        array(
            'slug'     => 'epilepsie',
            'title'    => __( 'Épilepsie', 'a11y-widget' ),
            'icon'     => 'bolt',
            'children' => array(
                array(
                    'slug'       => 'epilepsie-protection',
                    'label'      => __( 'Protection épilepsie', 'a11y-widget' ),
                    'hint'       => __( 'Bloquez les animations, GIFs, vidéos et flashs dangereux.', 'a11y-widget' ),
                    'aria_label' => __( 'Activer la protection contre les déclencheurs épileptiques', 'a11y-widget' ),
                    'template'   => 'epilepsy-protection',
                    'settings'   => array(
                        'intro'                    => __( 'Activez une protection médicale pour réduire les déclencheurs de crises : animations rapides, GIFs, vidéos en autoplay, effets parallax et flashs lumineux.', 'a11y-widget' ),
                        'stop_animations_label'    => __( 'Arrêter les animations', 'a11y-widget' ),
                        'stop_animations_hint'     => __( 'Force la durée des animations CSS et JavaScript à 0 s pour stopper les clignotements.', 'a11y-widget' ),
                        'stop_gifs_label'          => __( 'Figer les GIFs animés', 'a11y-widget' ),
                        'stop_gifs_hint'           => __( 'Capture la première image des GIFs et bloque ceux ajoutés dynamiquement.', 'a11y-widget' ),
                        'stop_videos_label'        => __( 'Bloquer l’autoplay des vidéos', 'a11y-widget' ),
                        'stop_videos_hint'         => __( 'Met en pause les vidéos et désactive l’autoplay sur YouTube/Vimeo.', 'a11y-widget' ),
                        'remove_parallax_label'    => __( 'Supprimer les effets parallax', 'a11y-widget' ),
                        'remove_parallax_hint'     => __( 'Neutralise les transformations 3D et les arrière-plans fixes.', 'a11y-widget' ),
                        'reduce_motion_label'      => __( 'Réduire tous les mouvements', 'a11y-widget' ),
                        'reduce_motion_hint'       => __( 'Force prefers-reduced-motion et désactive le défilement animé.', 'a11y-widget' ),
                        'block_flashing_label'     => __( 'Détecter et bloquer les flashs', 'a11y-widget' ),
                        'block_flashing_hint'      => __( 'Analyse la luminosité pour couper la page en cas de flash dangereux.', 'a11y-widget' ),
                        'activate_all_label'       => __( 'Activer toutes les protections', 'a11y-widget' ),
                        'activate_all_aria'        => __( 'Activer immédiatement toutes les protections épilepsie', 'a11y-widget' ),
                        'activate_all_confirm'     => __( 'Activer toutes les protections critiques ? Cela arrêtera animations, GIFs, vidéos, effets parallax, mouvements et flashs.', 'a11y-widget' ),
                        'reset_label'              => __( 'Réinitialiser les protections', 'a11y-widget' ),
                        'reset_aria'               => __( 'Réinitialiser les protections épilepsie', 'a11y-widget' ),
                        'live_region_label'        => __( 'Notifications des protections épilepsie', 'a11y-widget' ),
                        'gif_placeholder_label'    => __( 'GIF désactivé pour votre sécurité', 'a11y-widget' ),
                        'gif_placeholder_hint'     => __( 'Un GIF animé a été masqué pour éviter les déclencheurs lumineux.', 'a11y-widget' ),
                        'flash_overlay_title'      => __( 'Flash dangereux détecté', 'a11y-widget' ),
                        'flash_overlay_body'       => __( 'La page est temporairement masquée pour éviter un déclencheur photosensible.', 'a11y-widget' ),
                        'flash_overlay_dismiss'    => __( 'Fermer l’alerte', 'a11y-widget' ),
                    ),
                ),
            ),
        ),
        array(
            'slug'     => 'braille',
            'title'    => __( 'Braille', 'a11y-widget' ),
            'icon'     => 'braille',
            'children' => array(
                array(
                    'slug'       => 'braille-contracte',
                    'label'      => __( 'Braille contracté', 'a11y-widget' ),
                    'aria_label' => __( 'Activer le traducteur braille contracté', 'a11y-widget' ),
                    'template'   => 'braille-translator',
                    'settings'   => array(
                        'mode'               => 'contracted',
                        'selection_label'    => __( 'Texte sélectionné', 'a11y-widget' ),
                        'selection_missing'  => __( 'Aucun texte n’est sélectionné pour le moment.', 'a11y-widget' ),
                        'selection_truncated' => __( 'Seuls les 600 premiers caractères de la sélection ont été traduits.', 'a11y-widget' ),
                        'result_label'       => __( 'Résultat', 'a11y-widget' ),
                        'result_aria'        => __( 'Traduction en braille contracté', 'a11y-widget' ),
                        'live_label'         => __( 'Annonce braille contracté', 'a11y-widget' ),
                        'sr_result_prefix'   => __( 'Traduction braille contracté :', 'a11y-widget' ),
                        'sr_result_cleared'  => __( 'Résultat braille contracté effacé.', 'a11y-widget' ),
                    ),
                ),
                array(
                    'slug'       => 'braille-decontracte',
                    'label'      => __( 'Braille non contracté', 'a11y-widget' ),
                    'aria_label' => __( 'Activer le traducteur braille non contracté', 'a11y-widget' ),
                    'template'   => 'braille-translator',
                    'settings'   => array(
                        'mode'               => 'uncontracted',
                        'selection_label'    => __( 'Texte sélectionné', 'a11y-widget' ),
                        'selection_missing'  => __( 'Aucun texte n’est sélectionné pour le moment.', 'a11y-widget' ),
                        'selection_truncated' => __( 'Seuls les 600 premiers caractères de la sélection ont été traduits.', 'a11y-widget' ),
                        'result_label'       => __( 'Résultat', 'a11y-widget' ),
                        'result_aria'        => __( 'Traduction en braille non contracté', 'a11y-widget' ),
                        'live_label'         => __( 'Annonce braille non contracté', 'a11y-widget' ),
                        'sr_result_prefix'   => __( 'Traduction braille non contracté :', 'a11y-widget' ),
                        'sr_result_cleared'  => __( 'Résultat braille non contracté effacé.', 'a11y-widget' ),
                    ),
                ),
            ),
        ),
        array(
            'slug'     => 'audition',
            'title'    => __( 'Audition', 'a11y-widget' ),
            'icon'     => 'ear',
            'children' => array(
                array(
                    'slug'       => 'audition-text-to-speech',
                    'label'      => __( 'Synthèse vocale', 'a11y-widget' ),
                    'hint'       => '',
                    'aria_label' => __( 'Activer la lecture à voix haute', 'a11y-widget' ),
                    'template'   => 'text-to-speech',
                    'settings'   => array(
                        'intro'             => '',
                        'mode_label'        => __( 'Mode de lecture', 'a11y-widget' ),
                        'mode_hint'         => __( '« Sélection » lit uniquement le texte mis en évidence. « Page entière » lit tout le contenu principal.', 'a11y-widget' ),
                        'mode_selection'    => __( 'Sélection', 'a11y-widget' ),
                        'mode_page'         => __( 'Page entière', 'a11y-widget' ),
                        'controls_label'    => __( 'Contrôles', 'a11y-widget' ),
                        'play_label'        => __( 'Lire', 'a11y-widget' ),
                        'pause_label'       => __( 'Pause', 'a11y-widget' ),
                        'stop_label'        => __( 'Stop', 'a11y-widget' ),
                        'status_ready'      => __( 'Prêt à lire', 'a11y-widget' ),
                        'status_selection'  => __( 'Sélectionnez du texte puis appuyez sur « Lire ».', 'a11y-widget' ),
                        'status_page'       => __( 'Prêt à lire la page entière.', 'a11y-widget' ),
                        'status_playing'    => __( 'Lecture en cours…', 'a11y-widget' ),
                        'status_paused'     => __( 'Lecture en pause', 'a11y-widget' ),
                        'status_stopped'    => __( 'Lecture arrêtée', 'a11y-widget' ),
                        'status_finished'   => __( 'Lecture terminée', 'a11y-widget' ),
                        'status_error'      => __( 'Erreur de lecture', 'a11y-widget' ),
                        'volume_label'      => __( 'Volume', 'a11y-widget' ),
                        'volume_decrease'   => __( 'Diminuer le volume', 'a11y-widget' ),
                        'volume_increase'   => __( 'Augmenter le volume', 'a11y-widget' ),
                        'rate_label'        => __( 'Vitesse de lecture', 'a11y-widget' ),
                        'rate_hint'         => __( '0,5x = lent • 1x = normal • 2x = rapide', 'a11y-widget' ),
                        'rate_decrease'     => __( 'Diminuer la vitesse de lecture', 'a11y-widget' ),
                        'rate_increase'     => __( 'Augmenter la vitesse de lecture', 'a11y-widget' ),
                        'voice_label'       => __( 'Voix', 'a11y-widget' ),
                        'voice_hint'        => '',
                        'voice_default'     => __( 'Voix du navigateur (auto)', 'a11y-widget' ),
                        'voice_loading'     => __( 'Chargement des voix…', 'a11y-widget' ),
                        'info_tip'          => '',
                        'info_shortcuts'    => '',
                        'error_no_text'     => __( 'Aucun texte à lire pour le moment.', 'a11y-widget' ),
                        'unsupported'       => __( 'La synthèse vocale n’est pas disponible sur ce navigateur.', 'a11y-widget' ),
                        'announce_enabled'  => __( 'Module Text-to-Speech activé', 'a11y-widget' ),
                        'announce_disabled' => __( 'Module Text-to-Speech désactivé', 'a11y-widget' ),
                        'announce_started'  => __( 'Lecture démarrée', 'a11y-widget' ),
                        'announce_paused'   => __( 'Lecture en pause', 'a11y-widget' ),
                        'announce_resumed'  => __( 'Lecture reprise', 'a11y-widget' ),
                        'announce_stopped'  => __( 'Lecture arrêtée', 'a11y-widget' ),
                        'announce_finished' => __( 'Lecture terminée', 'a11y-widget' ),
                        'announce_voice'    => __( 'Voix changée', 'a11y-widget' ),
                    ),
                ),
            ),
        ),
    );
}

/**
 * Retrieve the SVG markup for a named icon.
 *
 * Icon paths are adapted from the Lucide icon set (https://lucide.dev) and
 * distributed under the MIT License.
 *
 * @param string $icon_key Registered icon identifier.
 * @param array  $args     Optional arguments. Supports `class` for custom classes.
 *
 * @return string
 */
function a11y_widget_get_icon_markup( $icon_key, $args = array() ) {
    $icon_key = sanitize_key( $icon_key );

    if ( '' === $icon_key ) {
        return '';
    }

    $icons = array(
        'eye'    => array(
            'viewBox' => '0 0 24 24',
            'elements' => array(
                array(
                    'type' => 'path',
                    'd'    => 'M2 12s4.5-6 10-6 10 6 10 6-4.5 6-10 6S2 12 2 12Z',
                ),
                array(
                    'type' => 'circle',
                    'cx'   => '12',
                    'cy'   => '12',
                    'r'    => '3',
                ),
            ),
        ),
        'sun'    => array(
            'viewBox' => '0 0 24 24',
            'elements' => array(
                array(
                    'type' => 'circle',
                    'cx'   => '12',
                    'cy'   => '12',
                    'r'    => '4',
                ),
                array(
                    'type' => 'path',
                    'd'    => 'M12 2v2',
                ),
                array(
                    'type' => 'path',
                    'd'    => 'M12 20v2',
                ),
                array(
                    'type' => 'path',
                    'd'    => 'M4.93 4.93L6.34 6.34',
                ),
                array(
                    'type' => 'path',
                    'd'    => 'M17.66 17.66L19.07 19.07',
                ),
                array(
                    'type' => 'path',
                    'd'    => 'M2 12h2',
                ),
                array(
                    'type' => 'path',
                    'd'    => 'M20 12h2',
                ),
                array(
                    'type' => 'path',
                    'd'    => 'M4.93 19.07L6.34 17.66',
                ),
                array(
                    'type' => 'path',
                    'd'    => 'M17.66 6.34L19.07 4.93',
                ),
            ),
        ),
        'braille' => array(
            'viewBox' => '0 0 24 24',
            'elements' => array(
                array(
                    'type' => 'circle',
                    'cx'   => '7',
                    'cy'   => '7',
                    'r'    => '1.4',
                ),
                array(
                    'type' => 'circle',
                    'cx'   => '7',
                    'cy'   => '12',
                    'r'    => '1.4',
                ),
                array(
                    'type' => 'circle',
                    'cx'   => '7',
                    'cy'   => '17',
                    'r'    => '1.4',
                ),
                array(
                    'type' => 'circle',
                    'cx'   => '17',
                    'cy'   => '7',
                    'r'    => '1.4',
                ),
                array(
                    'type' => 'circle',
                    'cx'   => '17',
                    'cy'   => '12',
                    'r'    => '1.4',
                ),
                array(
                    'type' => 'circle',
                    'cx'   => '17',
                    'cy'   => '17',
                    'r'    => '1.4',
                ),
            ),
        ),
        'ear'    => array(
            'viewBox' => '0 0 24 24',
            'elements' => array(
                array(
                    'type' => 'path',
                    'd'    => 'M6 10a7 7 0 1 1 13 3.6a10 10 0 0 1 -2 2a8 8 0 0 0 -2 3a4.5 4.5 0 0 1 -6.8 1.4',
                ),
                array(
                    'type' => 'path',
                    'd'    => 'M10 10a3 3 0 1 1 5 2.2',
                ),
            ),
        ),
        'brain'  => array(
            'viewBox' => '0 0 24 24',
            'elements' => array(
                array(
                    'type' => 'path',
                    'd'    => 'M15.5 13a3.5 3.5 0 0 0 -3.5 3.5v1a3.5 3.5 0 0 0 7 0v-1.8',
                ),
                array(
                    'type' => 'path',
                    'd'    => 'M8.5 13a3.5 3.5 0 0 1 3.5 3.5v1a3.5 3.5 0 0 1 -7 0v-1.8',
                ),
                array(
                    'type' => 'path',
                    'd'    => 'M17.5 16a3.5 3.5 0 0 0 0 -7h-.5',
                ),
                array(
                    'type' => 'path',
                    'd'    => 'M19 9.3v-2.8a3.5 3.5 0 0 0 -7 0',
                ),
                array(
                    'type' => 'path',
                    'd'    => 'M6.5 16a3.5 3.5 0 0 1 0 -7h.5',
                ),
                array(
                    'type' => 'path',
                    'd'    => 'M5 9.3v-2.8a3.5 3.5 0 0 1 7 0v10',
                ),
            ),
        ),
        'hand'   => array(
            'viewBox' => '0 0 24 24',
            'elements' => array(
                array(
                    'type' => 'path',
                    'd'    => 'M10.05 4.575a1.575 1.575 0 1 0 -3.15 0v3m 3.15 -3v-1.5a1.575 1.575 0 0 1 3.15 0v1.5m -3.15 0 .075 5.925m 3.075 .75V4.575m0 0a1.575 1.575 0 0 1 3.15 0V15M6.9 7.575a1.575 1.575 0 1 0 -3.15 0v8.175a6.75 6.75 0 0 0 6.75 6.75h2.018a5.25 5.25 0 0 0 3.712 -1.538l1.732 -1.732a5.25 5.25 0 0 0 1.538 -3.712l.003 -2.024a.668 .668 0 0 1 .198 -.471 1.575 1.575 0 1 0 -2.228 -2.228 3.818 3.818 0 0 0 -1.12 2.687M6.9 7.575V12m6.27 4.318A4.49 4.49 0 0 1 16.35 15',
                ),
            ),
        ),
        'bolt'   => array(
            'viewBox' => '0 0 24 24',
            'elements' => array(
                array(
                    'type' => 'path',
                    'd'    => 'M13 2 4 14h7l-1 8 9-12h-7l1-8Z',
                ),
            ),
        ),
    );

    if ( ! isset( $icons[ $icon_key ] ) ) {
        return '';
    }

    $icon   = $icons[ $icon_key ];
    $class  = '';
    $output = '';

    if ( ! empty( $args['class'] ) ) {
        $classes = is_array( $args['class'] ) ? $args['class'] : preg_split( '/\s+/', (string) $args['class'] );
        $classes = array_map( 'sanitize_html_class', array_filter( (array) $classes ) );
        if ( ! empty( $classes ) ) {
            $class = implode( ' ', $classes );
        }
    }

    ob_start();
    ?>
    <svg
        viewBox="<?php echo esc_attr( isset( $icon['viewBox'] ) ? $icon['viewBox'] : '0 0 24 24' ); ?>"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        focusable="false"
        aria-hidden="true"
        <?php if ( '' !== $class ) : ?>class="<?php echo esc_attr( $class ); ?>"<?php endif; ?>
    >
        <?php foreach ( $icon['elements'] as $element ) :
            $type = isset( $element['type'] ) ? $element['type'] : '';

            if ( 'path' === $type && ! empty( $element['d'] ) ) :
                ?>
                <path d="<?php echo esc_attr( $element['d'] ); ?>" />
                <?php
            elseif ( 'circle' === $type && isset( $element['cx'], $element['cy'], $element['r'] ) ) :
                ?>
                <circle cx="<?php echo esc_attr( $element['cx'] ); ?>" cy="<?php echo esc_attr( $element['cy'] ); ?>" r="<?php echo esc_attr( $element['r'] ); ?>" />
                <?php
            endif;
        endforeach; ?>
    </svg>
    <?php
    $output = ob_get_clean();

    return trim( $output );
}

/**
 * Normalize nested children for a feature definition.
 *
 * @param array $feature Feature data.
 *
 * @return array
 */
function a11y_widget_normalize_nested_children( $feature ) {
    if ( ! is_array( $feature ) ) {
        return array();
    }

    if ( empty( $feature['children'] ) || ! is_array( $feature['children'] ) ) {
        if ( isset( $feature['children'] ) && ! is_array( $feature['children'] ) ) {
            unset( $feature['children'] );
        }

        return $feature;
    }

    $normalized_children = array();

    foreach ( $feature['children'] as $child ) {
        if ( ! is_array( $child ) || empty( $child['slug'] ) ) {
            continue;
        }

        $child_slug = sanitize_key( $child['slug'] );

        if ( '' === $child_slug ) {
            continue;
        }

        $child['slug'] = $child_slug;
        $normalized_children[] = a11y_widget_normalize_nested_children( $child );
    }

    $feature['children'] = $normalized_children;

    return $feature;
}

/**
 * Remove hint text from placeholder features, including nested children.
 *
 * A feature is considered a placeholder when it explicitly sets the
 * `placeholder` flag or when it originates from the Markdown directory and
 * does not define a custom template. These entries are meant to be wiring
 * stubs, so their descriptive text should remain hidden in the interface.
 *
 * @param array $feature Feature data.
 *
 * @return array
 */
function a11y_widget_strip_placeholder_hint_from_feature( $feature ) {
    if ( ! is_array( $feature ) ) {
        return $feature;
    }

    $has_placeholder_flag   = ! empty( $feature['placeholder'] );
    $has_markdown_origin    = isset( $feature['source'] );
    $defines_custom_template = isset( $feature['template'] ) && '' !== $feature['template'];

    if ( $has_placeholder_flag || ( $has_markdown_origin && ! $defines_custom_template ) ) {
        $feature['hint'] = '';
    }

    if ( ! empty( $feature['children'] ) && is_array( $feature['children'] ) ) {
        foreach ( $feature['children'] as $index => $child_feature ) {
            $feature['children'][ $index ] = a11y_widget_strip_placeholder_hint_from_feature( $child_feature );
        }
    }

    return $feature;
}

/**
 * Strip hint text from placeholder features within a collection of sections.
 *
 * @param array $sections Sections with their features.
 *
 * @return array
 */
function a11y_widget_strip_placeholder_hints( $sections ) {
    if ( empty( $sections ) || ! is_array( $sections ) ) {
        return $sections;
    }

    foreach ( $sections as $section_index => $section ) {
        if ( empty( $section['children'] ) || ! is_array( $section['children'] ) ) {
            continue;
        }

        foreach ( $section['children'] as $feature_index => $feature ) {
            $section['children'][ $feature_index ] = a11y_widget_strip_placeholder_hint_from_feature( $feature );
        }

        $sections[ $section_index ] = $section;
    }

    return $sections;
}

/**
 * Parse Markdown feature files located in the plugin `features/` directory.
 *
 * File format (per line, bullet list):
 *   # Mon titre de section (catégorie niveau 1)
 *   - `slug` **Label** : Hint optionnel (placeholders niveau 2)
 *
 * @return array[] Parsed sections.
 */
function a11y_widget_parse_markdown_sections() {
    static $cache = null;

    if ( null !== $cache ) {
        return $cache;
    }

    $dir = trailingslashit( A11Y_WIDGET_PATH ) . 'features';

    if ( ! is_dir( $dir ) ) {
        return array();
    }

    $files = glob( trailingslashit( $dir ) . '*.md' );
    if ( false === $files || empty( $files ) ) {
        return array();
    }

    sort( $files );

    $sections      = array();
    $section_order = array();

    foreach ( $files as $file ) {
        $lines = file( $file, FILE_IGNORE_NEW_LINES );
        if ( false === $lines ) {
            continue;
        }

        $current_section = null;

        foreach ( $lines as $raw_line ) {
            $line = trim( $raw_line );

            if ( '' === $line ) {
                continue;
            }

            if ( preg_match( '/^#{1,6}\s*(.+)$/u', $line, $matches ) ) {
                $title = wp_strip_all_tags( trim( $matches[1] ) );
                if ( '' === $title ) {
                    continue;
                }

                $slug = sanitize_title( $title );
                if ( '' === $slug ) {
                    $current_section = null;
                    continue;
                }

                if ( ! isset( $sections[ $slug ] ) ) {
                    $sections[ $slug ] = array(
                        'slug'           => $slug,
                        'title'          => $title,
                        'children'       => array(),
                        'children_order' => array(),
                    );
                    $section_order[] = $slug;
                } elseif ( '' === $sections[ $slug ]['title'] ) {
                    $sections[ $slug ]['title'] = $title;
                }

                $current_section = $slug;
                continue;
            }

            if ( 0 !== strpos( $line, '-' ) || null === $current_section ) {
                continue;
            }

            if ( preg_match( '/-\s*`([^`]+)`\s*(?:\*\*(.+?)\*\*|([^:]+))?\s*(?::\s*(.+))?$/u', $line, $matches ) ) {
                $slug = sanitize_key( $matches[1] );
                if ( '' === $slug ) {
                    continue;
                }

                if ( isset( $sections[ $current_section ]['children'][ $slug ] ) ) {
                    continue;
                }

                $raw_label = '';
                if ( ! empty( $matches[2] ) ) {
                    $raw_label = $matches[2];
                } elseif ( ! empty( $matches[3] ) ) {
                    $raw_label = trim( $matches[3] );
                }

                if ( '' === $raw_label ) {
                    $raw_label = $slug;
                }

                $raw_label = wp_strip_all_tags( $raw_label );

                $hint = '';
                if ( isset( $matches[4] ) ) {
                    $hint = wp_strip_all_tags( trim( $matches[4] ) );
                }

                $sections[ $current_section ]['children'][ $slug ] = array(
                    'slug'       => $slug,
                    'label'      => $raw_label,
                    'hint'       => $hint,
                    'aria_label' => sprintf( __( 'Activer %s', 'a11y-widget' ), $raw_label ),
                    'source'     => basename( $file ),
                );
                $sections[ $current_section ]['children_order'][] = $slug;
            }
        }
    }

    $ordered_sections = array();
    foreach ( $section_order as $slug ) {
        if ( ! isset( $sections[ $slug ] ) ) {
            continue;
        }

        $section = $sections[ $slug ];
        $ordered_children = array();
        if ( ! empty( $section['children_order'] ) ) {
            foreach ( $section['children_order'] as $child_slug ) {
                if ( isset( $section['children'][ $child_slug ] ) ) {
                    $ordered_children[] = $section['children'][ $child_slug ];
                }
            }
        }

        $section['children'] = $ordered_children;
        unset( $section['children_order'] );

        $ordered_sections[] = $section;
    }

    $cache = $ordered_sections;

    return $cache;
}

/**
 * Merge default and Markdown-defined sections without overwriting existing slugs.
 *
 * @return array[]
 */
function a11y_widget_get_sections() {
    $defaults          = a11y_widget_get_default_sections();
    $sections_by_slug  = array();
    $ordered_slugs     = array();
    $child_slug_global = array();

    foreach ( $defaults as $section ) {
        if ( empty( $section['slug'] ) ) {
            continue;
        }

        $slug = sanitize_title( $section['slug'] );
        if ( '' === $slug ) {
            continue;
        }

        if ( ! isset( $sections_by_slug[ $slug ] ) ) {
            $sections_by_slug[ $slug ] = array(
                'slug'           => $slug,
                'title'          => isset( $section['title'] ) ? $section['title'] : '',
                'icon'           => isset( $section['icon'] ) ? sanitize_key( $section['icon'] ) : '',
                'children'       => array(),
                'children_order' => array(),
            );
            $ordered_slugs[] = $slug;
        } else {
            if ( isset( $section['title'] ) && '' !== $section['title'] && '' === $sections_by_slug[ $slug ]['title'] ) {
                $sections_by_slug[ $slug ]['title'] = $section['title'];
            }

            if ( isset( $section['icon'] ) && '' === $sections_by_slug[ $slug ]['icon'] ) {
                $sections_by_slug[ $slug ]['icon'] = sanitize_key( $section['icon'] );
            }
        }

        $children = array();
        if ( isset( $section['children'] ) && is_array( $section['children'] ) ) {
            $children = $section['children'];
        }

        foreach ( $children as $child ) {
            if ( empty( $child['slug'] ) ) {
                continue;
            }

            $child_slug = sanitize_key( $child['slug'] );
            if ( '' === $child_slug ) {
                continue;
            }

            if ( isset( $sections_by_slug[ $slug ]['children'][ $child_slug ] ) ) {
                continue;
            }

            $child['slug'] = $child_slug;
            $child         = a11y_widget_normalize_nested_children( $child );
            $sections_by_slug[ $slug ]['children'][ $child_slug ] = $child;
            $sections_by_slug[ $slug ]['children_order'][]        = $child_slug;
            $child_slug_global[ $child_slug ]                     = true;
        }
    }

    $extra_sections = a11y_widget_parse_markdown_sections();

    foreach ( $extra_sections as $section ) {
        if ( empty( $section['slug'] ) ) {
            continue;
        }

        $slug = sanitize_title( $section['slug'] );
        if ( '' === $slug ) {
            continue;
        }

        if ( ! isset( $sections_by_slug[ $slug ] ) ) {
            $sections_by_slug[ $slug ] = array(
                'slug'           => $slug,
                'title'          => isset( $section['title'] ) ? $section['title'] : '',
                'icon'           => isset( $section['icon'] ) ? sanitize_key( $section['icon'] ) : '',
                'children'       => array(),
                'children_order' => array(),
            );
            $ordered_slugs[] = $slug;
        } else {
            if ( '' !== $section['title'] && '' === $sections_by_slug[ $slug ]['title'] ) {
                $sections_by_slug[ $slug ]['title'] = $section['title'];
            }

            if ( isset( $section['icon'] ) && '' === $sections_by_slug[ $slug ]['icon'] ) {
                $sections_by_slug[ $slug ]['icon'] = sanitize_key( $section['icon'] );
            }
        }

        if ( empty( $section['children'] ) ) {
            continue;
        }

        foreach ( $section['children'] as $child ) {
            if ( empty( $child['slug'] ) ) {
                continue;
            }

            $child_slug = sanitize_key( $child['slug'] );
            if ( '' === $child_slug ) {
                continue;
            }

            if ( isset( $child_slug_global[ $child_slug ] ) ) {
                continue;
            }

            if ( isset( $sections_by_slug[ $slug ]['children'][ $child_slug ] ) ) {
                continue;
            }

            $child['slug']                                   = $child_slug;
            $child                                           = a11y_widget_normalize_nested_children( $child );
            $child_slug_global[ $child_slug ]                = true;
            $sections_by_slug[ $slug ]['children'][ $child_slug ] = $child;
            $sections_by_slug[ $slug ]['children_order'][]        = $child_slug;
        }
    }

    $sections = array();
    foreach ( $ordered_slugs as $slug ) {
        if ( ! isset( $sections_by_slug[ $slug ] ) ) {
            continue;
        }

        $section = $sections_by_slug[ $slug ];
        if ( ! isset( $section['title'] ) || ! is_string( $section['title'] ) ) {
            $section['title'] = '';
        }

        $ordered_children = array();
        if ( isset( $section['children_order'] ) && is_array( $section['children_order'] ) ) {
            foreach ( $section['children_order'] as $child_slug ) {
                if ( isset( $section['children'][ $child_slug ] ) ) {
                    $ordered_children[] = $section['children'][ $child_slug ];
                }
            }
        }

        $section['children'] = $ordered_children;
        unset( $section['children_order'] );

        $sections[] = $section;
    }

    $sections = a11y_widget_apply_custom_feature_layout( $sections );
    $sections = a11y_widget_apply_custom_section_order( $sections );
    $sections = a11y_widget_strip_placeholder_hints( $sections );

    /**
     * Filter the final list of sections sent to the template.
     *
     * @param array $sections Sections with children.
     */
    return apply_filters( 'a11y_widget_sections', $sections );
}

/**
 * Apply the administrator-defined feature layout to sections.
 *
 * @param array $sections Sections with their features.
 *
 * @return array
 */
function a11y_widget_apply_custom_feature_layout( $sections ) {
    if ( empty( $sections ) || ! is_array( $sections ) ) {
        return array();
    }

    if ( ! function_exists( 'a11y_widget_get_feature_layout' ) ) {
        return $sections;
    }

    $layout = a11y_widget_get_feature_layout();

    if ( empty( $layout ) || ! is_array( $layout ) ) {
        return $sections;
    }

    $feature_map = array();

    foreach ( $sections as $section ) {
        if ( empty( $section['children'] ) || ! is_array( $section['children'] ) ) {
            continue;
        }

        foreach ( $section['children'] as $feature ) {
            if ( empty( $feature['slug'] ) ) {
                continue;
            }

            $feature_slug = sanitize_key( $feature['slug'] );

            if ( '' === $feature_slug ) {
                continue;
            }

            $feature['slug']          = $feature_slug;
            $feature_map[ $feature_slug ] = $feature;
        }
    }

    if ( empty( $feature_map ) ) {
        return $sections;
    }

    $assigned            = array();
    $feature_destination = array();

    foreach ( $layout as $section_slug => $child_slugs ) {
        if ( ! is_array( $child_slugs ) ) {
            continue;
        }

        $section_slug = sanitize_title( $section_slug );

        if ( '' === $section_slug ) {
            continue;
        }

        foreach ( $child_slugs as $child_slug ) {
            $child_slug = sanitize_key( $child_slug );

            if ( '' === $child_slug ) {
                continue;
            }

            if ( isset( $feature_destination[ $child_slug ] ) ) {
                continue;
            }

            $feature_destination[ $child_slug ] = $section_slug;
        }
    }

    foreach ( $sections as &$section ) {
        if ( empty( $section['slug'] ) ) {
            continue;
        }

        $section_slug = sanitize_title( $section['slug'] );

        if ( '' === $section_slug ) {
            continue;
        }

        $ordered_children = array();

        if ( isset( $layout[ $section_slug ] ) && is_array( $layout[ $section_slug ] ) ) {
            foreach ( $layout[ $section_slug ] as $child_slug ) {
                $child_slug = sanitize_key( $child_slug );

                if ( '' === $child_slug ) {
                    continue;
                }

                if ( isset( $assigned[ $child_slug ] ) || ! isset( $feature_map[ $child_slug ] ) ) {
                    continue;
                }

                $feature = $feature_map[ $child_slug ];
                $feature['slug'] = $child_slug;

                $ordered_children[]      = $feature;
                $assigned[ $child_slug ] = true;
            }
        }

        if ( isset( $section['children'] ) && is_array( $section['children'] ) ) {
            foreach ( $section['children'] as $feature ) {
                if ( empty( $feature['slug'] ) ) {
                    continue;
                }

                $child_slug = sanitize_key( $feature['slug'] );

                if ( '' === $child_slug || isset( $assigned[ $child_slug ] ) || ! isset( $feature_map[ $child_slug ] ) ) {
                    continue;
                }

                if ( isset( $feature_destination[ $child_slug ] ) && $feature_destination[ $child_slug ] !== $section_slug ) {
                    continue;
                }

                $feature['slug']         = $child_slug;
                $ordered_children[]      = $feature;
                $assigned[ $child_slug ] = true;
            }
        }

        $section['children'] = $ordered_children;
    }
    unset( $section );

    return $sections;
}

/**
 * Apply the administrator-defined section order.
 *
 * @param array $sections Sections with their features.
 *
 * @return array
 */
function a11y_widget_apply_custom_section_order( $sections ) {
    if ( empty( $sections ) || ! is_array( $sections ) ) {
        return $sections;
    }

    if ( ! function_exists( 'a11y_widget_get_section_order' ) ) {
        return $sections;
    }

    $order = a11y_widget_get_section_order();

    if ( empty( $order ) || ! is_array( $order ) ) {
        return $sections;
    }

    $sections_by_slug = array();

    foreach ( $sections as $section ) {
        if ( empty( $section['slug'] ) ) {
            continue;
        }

        $slug = sanitize_title( $section['slug'] );

        if ( '' === $slug || isset( $sections_by_slug[ $slug ] ) ) {
            continue;
        }

        $section['slug']        = $slug;
        $sections_by_slug[ $slug ] = $section;
    }

    if ( empty( $sections_by_slug ) ) {
        return $sections;
    }

    $ordered = array();

    foreach ( $order as $slug ) {
        $slug = sanitize_title( $slug );

        if ( '' === $slug || ! isset( $sections_by_slug[ $slug ] ) ) {
            continue;
        }

        $ordered[] = $sections_by_slug[ $slug ];
        unset( $sections_by_slug[ $slug ] );
    }

    if ( empty( $ordered ) ) {
        return $sections;
    }

    foreach ( $sections as $section ) {
        if ( empty( $section['slug'] ) ) {
            continue;
        }

        $slug = sanitize_title( $section['slug'] );

        if ( '' === $slug || ! isset( $sections_by_slug[ $slug ] ) ) {
            continue;
        }

        $ordered[] = $sections_by_slug[ $slug ];
        unset( $sections_by_slug[ $slug ] );
    }

    return array_values( $ordered );
}

// Load admin settings and feature visibility management.
require_once A11Y_WIDGET_PATH . 'includes/admin-settings.php';

/**
 * Auto-inject in footer unless disabled
 */
function a11y_widget_auto_inject() {
    $enable_auto = apply_filters( 'a11y_widget_enable_auto', true );
    if ( $enable_auto ) {
        a11y_widget_markup();
    }
}
add_action( 'wp_footer', 'a11y_widget_auto_inject', 5 );

/**
 * Shortcode: [a11y_widget]
 */
function a11y_widget_shortcode() {
    ob_start();
    include A11Y_WIDGET_PATH . 'templates/widget.php';
    return ob_get_clean();
}
add_shortcode( 'a11y_widget', 'a11y_widget_shortcode' );
