<?php
/**
 * Exemplo de configuração local do Stripe.
 * Copie este ficheiro para stripe_config.local.php e coloque a sua chave secreta.
 * O ficheiro stripe_config.local.php não deve ser commitado (está no .gitignore).
 *
 * Obtenha a chave em: https://dashboard.stripe.com/apikeys
 * Use sk_test_... para testes e sk_live_... para produção.
 */
define('STRIPE_SECRET_KEY', 'sk_test_XXXXXXXX'); // Substitua pela sua Secret Key do Stripe
