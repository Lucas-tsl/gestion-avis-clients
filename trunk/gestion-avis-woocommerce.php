<?php
/**
 * Plugin Name: Gestion Avis WooCommerce
 * Description: Plugin pour limiter les avis produits et injecter Google Avis Clients avec configuration admin.
 * Version: 1.0
 * Author: Troteseil Lucas
 */

if (!defined('ABSPATH')) exit;

// === MENU ADMIN PERSONNALISÉ ===
add_action('admin_menu', function () {
    add_menu_page(
        'Gestion des Avis',
        'Gestion Avis',
        'manage_options',
        'gestion-avis',
        '__render_gestion_avis_page',
        'dashicons-testimonial',
        56
    );

    add_submenu_page(
        'gestion-avis',
        'Limiter les avis',
        'Limiter les avis',
        'manage_options',
        'limiter-avis',
        '__render_limiter_avis_page'
    );

    add_submenu_page(
        'gestion-avis',
        'Google Avis Clients',
        'Google Avis Clients',
        'manage_options',
        'google-avis',
        '__render_google_avis_page'
    );
});

// === PAGES ADMIN ===
function __render_gestion_avis_page() {
    echo '<div class="wrap"><h1>Gestion des Avis WooCommerce</h1><p>Utilisez les sous-menus pour configurer les options.</p></div>';
}

function __render_limiter_avis_page() {
    ?>
    <div class="wrap">
        <h2>Limiter les avis produits</h2>
        <form method="post" action="options.php">
            <?php
                settings_fields('limiter_avis_group');
                do_settings_sections('limiter-avis');
                submit_button();
            ?>
        </form>
    </div>
    <?php
}

function __render_google_avis_page() {
    ?>
    <div class="wrap">
        <h2>Google Avis Clients</h2>
        <form method="post" action="options.php">
            <?php
                settings_fields('google_avis_group');
                do_settings_sections('google-avis');
                submit_button();
            ?>
        </form>
    </div>
    <?php
}

// === ENREGISTREMENT DES OPTIONS ===
add_action('admin_init', function () {
    // Limiter les avis
    register_setting('limiter_avis_group', 'limiter_avis_enabled');
    add_settings_section('limiter_avis_section', '', null, 'limiter-avis');
    add_settings_field('limiter_avis_enabled', 'Activer la limitation à 3 avis', function () {
        $val = get_option('limiter_avis_enabled');
        echo '<input type="checkbox" name="limiter_avis_enabled" value="1" ' . checked(1, $val, false) . '> Activer';
    }, 'limiter-avis', 'limiter_avis_section');

    // Google Avis
    register_setting('google_avis_group', 'google_merchant_id');
    add_settings_section('google_avis_section', '', null, 'google-avis');
    add_settings_field('google_merchant_id', 'ID Marchand Google', function () {
        $val = get_option('google_merchant_id');
        echo '<input type="text" name="google_merchant_id" value="' . esc_attr($val) . '" placeholder="Ex: 1234567890">';
    }, 'google-avis', 'google_avis_section');
});


// === FONCTIONNALITÉ : LIMITER LES AVIS ===
add_filter('comments_array', function ($comments, $post_id) {
    if (!get_option('limiter_avis_enabled')) return $comments;
    if (!is_product()) return $comments;
    if (isset($_GET['afficher_tous_les_avis']) || (defined('DOING_AJAX') && DOING_AJAX)) return $comments;
    return array_slice($comments, 0, 3);
}, 10, 2);

add_action('comment_form_before', function () {
    if (!get_option('limiter_avis_enabled')) return;
    if (!is_product()) return;

    global $product;
    if (get_comments_number($product->get_id()) <= 3) return;

    echo '<div id="voir-plus-avis-container" style="text-align:center; margin: 20px 0;">
        <button id="voir-plus-avis" style="padding:10px 20px; cursor:pointer;">Voir plus d’avis</button>
    </div>';
});

add_action('wp_footer', function () {
    if (!get_option('limiter_avis_enabled')) return;
    if (!is_product()) return;

    global $wp;
    $product_url = home_url(add_query_arg([], $wp->request));

    echo "<script>
    document.addEventListener('DOMContentLoaded', function () {
        const bouton = document.getElementById('voir-plus-avis');
        if (bouton) {
            bouton.addEventListener('click', function () {
                bouton.disabled = true;
                bouton.textContent = 'Chargement...';
                fetch('{$product_url}?afficher_tous_les_avis=1')
                    .then(r => r.text())
                    .then(html => {
                        const doc = new DOMParser().parseFromString(html, 'text/html');
                        const nouveaux = doc.querySelector('#reviews .commentlist');
                        const actuels = document.querySelector('#reviews .commentlist');
                        if (actuels && nouveaux) {
                            actuels.innerHTML = nouveaux.innerHTML;
                            bouton.style.display = 'none';
                        } else {
                            bouton.textContent = 'Erreur';
                            bouton.disabled = false;
                        }
                    });
            });
        }
    });
    </script>";
}, 99);

// === FONCTIONNALITÉ : GOOGLE AVIS CLIENTS ===
add_action('wp_footer', function () {
    if (!is_order_received_page()) return;

    $merchant_id = get_option('google_merchant_id');
    if (!$merchant_id) return;

    $order_id = isset($_GET['key']) ? wc_get_order_id_by_order_key(wc_clean($_GET['key'])) : 0;
    if (!$order_id) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    $email = $order->get_billing_email();
    $country = $order->get_shipping_country() ?: 'FR';
    $date = date('Y-m-d', strtotime('+3 days'));
    $products = [];

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product) {
            $gtin = $product->get_meta('_gtin');
            if ($gtin) $products[] = ['gtin' => $gtin];
        }
    }

    $products_json = json_encode($products);

    echo '<script src="https://apis.google.com/js/platform.js?onload=renderOptIn" async defer></script>';
    echo "<script>
    window.renderOptIn = function() {
        window.gapi.load('surveyoptin', function() {
            window.gapi.surveyoptin.render({
                merchant_id: {$merchant_id},
                order_id: '" . esc_js($order->get_order_number()) . "',
                email: '" . esc_js($email) . "',
                delivery_country: '" . esc_js($country) . "',
                estimated_delivery_date: '" . esc_js($date) . "',
                products: {$products_json},
                opt_in_style: 'BOTTOM_LEFT'
            });
        });
    };
    </script>";
});

