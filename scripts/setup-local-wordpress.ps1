$ErrorActionPreference = 'Stop'
if (Get-Variable PSNativeCommandUseErrorActionPreference -ErrorAction SilentlyContinue) {
    $PSNativeCommandUseErrorActionPreference = $false
}

docker compose up -d db wordpress

$adminUser = if ($env:WLP_LOCAL_ADMIN_USER) { $env:WLP_LOCAL_ADMIN_USER } else { 'wlp-admin' }
$adminPassword = if ($env:WLP_LOCAL_ADMIN_PASSWORD) { $env:WLP_LOCAL_ADMIN_PASSWORD } else { 'change-me-local-only' }
$adminEmail = if ($env:WLP_LOCAL_ADMIN_EMAIL) { $env:WLP_LOCAL_ADMIN_EMAIL } else { 'wlp-admin@example.com' }

Write-Host 'Waiting for WordPress database access...'
docker compose run --rm wpcli sh -c 'until wp db check --allow-root; do sleep 3; done'

docker compose run --rm wpcli wp core is-installed --allow-root 2>$null
$isInstalled = $LASTEXITCODE -eq 0
if (-not $isInstalled) {
    docker compose run --rm wpcli wp core install `
        --url='http://localhost:8080' `
        --title='Woo Logistics Local' `
        --admin_user="$adminUser" `
        --admin_password="$adminPassword" `
        --admin_email="$adminEmail" `
        --skip-email `
        --allow-root
}

docker compose exec wordpress sh -c 'mkdir -p /var/www/html/wp-content/upgrade /var/www/html/wp-content/uploads && chmod -R 777 /var/www/html/wp-content'

docker compose run --rm wpcli wp plugin is-installed woocommerce --allow-root 2>$null
if ($LASTEXITCODE -ne 0) {
    docker compose run --rm wpcli wp plugin install woocommerce --activate --allow-root
} else {
docker compose run --rm wpcli wp plugin activate woocommerce --allow-root
}

docker compose run --rm wpcli wp plugin activate woo-logistics-plugin --allow-root
docker compose run --rm wpcli wp user get "$adminUser" --allow-root 2>$null
if ($LASTEXITCODE -ne 0) {
    docker compose run --rm wpcli wp user create "$adminUser" "$adminEmail" --role=administrator --user_pass="$adminPassword" --allow-root
} else {
    docker compose run --rm wpcli wp user update "$adminUser" --role=administrator --user_pass="$adminPassword" --allow-root
}
docker compose run --rm wpcli wp eval-file wp-content/plugins/woo-logistics-plugin/scripts/seed-local-wordpress.php --allow-root

Write-Host ''
Write-Host 'Local WordPress is ready: http://localhost:8080/wp-admin/'
Write-Host "Username: $adminUser"
Write-Host 'Password: set by WLP_LOCAL_ADMIN_PASSWORD or the local-only default'
Write-Host 'Plugin screen: http://localhost:8080/wp-admin/admin.php?page=wlp-logistics'
Write-Host 'Settings: http://localhost:8080/wp-admin/admin.php?page=wlp-logistics-settings'
