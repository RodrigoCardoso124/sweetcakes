<?php
/**
 * Configuração Stripe.
 * A chave secreta pode ser definida de 3 formas (por ordem de prioridade):
 * 1. Variável de ambiente STRIPE_SECRET_KEY (ex.: no Apache ou .env)
 * 2. Ficheiro stripe_config.local.php (copie stripe_config.local.example.php para stripe_config.local.php e edite)
 * 3. Valor por defeito abaixo (apenas para desenvolvimento - substitua pela sua chave)
 *
 * Obtenha a chave em: https://dashboard.stripe.com/apikeys (Secret key - começa com sk_test_ ou sk_live_)
 * NUNCA commite a chave secreta em repositórios públicos.
 */
if (file_exists(__DIR__ . '/stripe_config.local.php')) {
    require_once __DIR__ . '/stripe_config.local.php';
}
if (!defined('STRIPE_SECRET_KEY')) {
    define('STRIPE_SECRET_KEY', getenv('STRIPE_SECRET_KEY') ?: 'sk_test_XXXXXXXX');
}
