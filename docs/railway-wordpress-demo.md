# Railway WordPress/WooCommerce Demo Runbook

This runbook documents the hosted demo setup for the Woo Logistics Plugin. It is intended for future agents so the Railway and Cloudflare setup can be repeated without rediscovering the same issues.

## Goal

Host a real WordPress + WooCommerce demo on Railway, reachable through a Cloudflare-managed subdomain.

Current demo:

- Site: `https://woo-logistics-demo.northendtech.ca`
- Railway project: `woo-logistics-demo`
- Railway services: `wordpress`, `MySQL`
- Cloudflare zone: `northendtech.ca`

This is not a tunnel. Do not use Cloudflare Tunnel for this setup.

## Reference Docs

- Railway WordPress template: `https://railway.com/deploy/EP4wIt`
- Railway WooCommerce template: `https://railway.com/deploy/woocommerce`
- Railway database/private networking docs should be checked before changing DB connectivity.

## Architecture

Use two Railway services:

- `wordpress`: custom Dockerfile based on `wordpress:php8.2-apache`
- `MySQL`: Railway-managed MySQL service

Use Cloudflare only for DNS:

- DNS-only CNAME for the app hostname
- TXT record for Railway custom-domain ownership verification

Do not proxy through Cloudflare while Railway is validating the domain. Keep the DNS record DNS-only.

## Railway Database Variables

Inside Railway, prefer Railway reference variables instead of copied literal database values:

```text
WORDPRESS_DB_HOST=${{MySQL.MYSQLHOST}}:${{MySQL.MYSQLPORT}}
WORDPRESS_DB_USER=${{MySQL.MYSQLUSER}}
WORDPRESS_DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
WORDPRESS_DB_NAME=${{MySQL.MYSQLDATABASE}}
```

These resolve inside the `wordpress` service and keep credentials managed by Railway.

Important:

- Inside Railway, use the private host such as `mysql.railway.internal`.
- From the developer machine, private `*.railway.internal` hosts are not reachable. Use the public proxy URL only for local diagnostics.
- Do not paste secret values into chat or committed files.

## Entrypoint Pattern

The custom entrypoint must let the official WordPress image entrypoint own Apache in the foreground:

```bash
setup_wordpress &
exec docker-entrypoint.sh "$@"
```

Do not run `docker-entrypoint.sh "$@"` in the background and then `wait`; that caused startup/routing problems.

The setup worker should:

1. Wait for DB connectivity.
2. Wait for `/var/www/html/wp-config.php`.
3. Run `wp core install` if needed.
4. Update `home` and `siteurl`.
5. Create or update the demo admin user.
6. Install/activate WooCommerce.
7. Activate `woo-logistics-plugin`.
8. Optionally run `scripts/seed-local-wordpress.php`.

Use a PHP `mysqli` readiness check, not `wp db check`. `wp db check` shells out to `mariadb-check`, which can fail against Railway's public proxy with TLS certificate errors.

## Apache MPM

The WordPress image can fail with:

```text
AH00534: apache2: Configuration error: More than one MPM loaded.
```

The Dockerfile and runtime entrypoint should force `mpm_prefork` and remove `mpm_event` / `mpm_worker` links. This is already handled in the current `Dockerfile` and `railway-entrypoint.sh`.

## Cloudflare DNS

After creating the Railway custom domain, Railway returns:

- A traffic CNAME target
- A TXT verification token

Create both records in Cloudflare.

Example for the current demo:

```text
Type: CNAME
Name: woo-logistics-demo
Target: ofzxl7ea.up.railway.app
Proxy: DNS only
```

```text
Type: TXT
Name: _railway-verify.woo-logistics-demo
Value: railway-verify=<token-from-railway>
```

The CNAME target and TXT token can change if the Railway custom domain is recreated. Always read the current values from Railway instead of reusing old ones.

## Commands

Create the Railway project and services:

```powershell
railway init --name woo-logistics-demo
railway add --database mysql
railway add --service wordpress
```

Set WordPress variables:

```powershell
railway variable set --service wordpress 'WORDPRESS_DB_HOST=${{MySQL.MYSQLHOST}}:${{MySQL.MYSQLPORT}}'
railway variable set --service wordpress 'WORDPRESS_DB_USER=${{MySQL.MYSQLUSER}}'
railway variable set --service wordpress 'WORDPRESS_DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}'
railway variable set --service wordpress 'WORDPRESS_DB_NAME=${{MySQL.MYSQLDATABASE}}'
railway variable set --service wordpress 'WORDPRESS_SITE_URL=https://woo-logistics-demo.northendtech.ca'
railway variable set --service wordpress 'WORDPRESS_ADMIN_USER=goodlife'
railway variable set --service wordpress 'WORDPRESS_ADMIN_PASSWORD=<demo-admin-password>'
railway variable set --service wordpress 'WORDPRESS_ADMIN_EMAIL=goodlife@example.com'
```

Set demo Canada Post variables. Keep secret values in Railway variables only:

```powershell
railway variable set --service wordpress 'WLP_CP_SANDBOX=yes'
railway variable set --service wordpress 'WLP_CP_API_USER=<canada-post-sandbox-user>'
railway variable set --service wordpress 'WLP_CP_API_PASSWORD=<canada-post-sandbox-password>'
railway variable set --service wordpress 'WLP_CP_CUSTOMER_NUMBER=<canada-post-customer-number>'
railway variable set --service wordpress 'WLP_CP_ORIGIN_NAME=Demo Warehouse'
railway variable set --service wordpress 'WLP_CP_ORIGIN_COMPANY=North End Tech Demo'
railway variable set --service wordpress 'WLP_CP_ORIGIN_EMAIL=goodlife@example.com'
railway variable set --service wordpress 'WLP_CP_ORIGIN_PHONE=<ten-digit-origin-phone>'
railway variable set --service wordpress 'WLP_CP_ORIGIN_ADDRESS_1=290 Bremner Blvd'
railway variable set --service wordpress 'WLP_CP_ORIGIN_CITY=Toronto'
railway variable set --service wordpress 'WLP_CP_ORIGIN_PROVINCE=ON'
railway variable set --service wordpress 'WLP_CP_ORIGIN_POSTAL_CODE=M5V3L9'
railway variable set --service wordpress 'WLP_CP_CUSTOMER_NOTIFICATIONS=yes'
railway variable set --service wordpress 'WLP_DEFAULT_SERVICE_CODE=DOM.XP'
railway variable set --service wordpress 'WLP_CALCULATE_PRODUCT_WEIGHT=yes'
railway variable set --service wordpress 'WLP_USE_BASE_PACKAGE_WEIGHT=yes'
railway variable set --service wordpress 'WLP_BASE_PACKAGE_WEIGHT_KG=0.05'
```

The deployment entrypoint copies these `WLP_*` variables into the plugin's WordPress options so the settings page is visibly prefilled for demos. The plugin's Canada Post client also keeps env-var fallbacks for direct request execution.

Deploy:

```powershell
railway up --service wordpress --detach -m "Deploy Woo logistics WordPress demo"
```

Create the Railway custom domain:

```powershell
railway domain woo-logistics-demo.northendtech.ca --service wordpress --port 80 --json
```

Then add the returned DNS records in Cloudflare.

## Verification

Check Railway service state:

```powershell
railway service list --json
railway logs --service wordpress --lines 200
```

Check DNS:

```powershell
Resolve-DnsName woo-logistics-demo.northendtech.ca -Type CNAME
Resolve-DnsName _railway-verify.woo-logistics-demo.northendtech.ca -Type TXT
```

Check HTTP:

```powershell
curl.exe -I https://woo-logistics-demo.northendtech.ca
curl.exe -I https://woo-logistics-demo.northendtech.ca/wp-login.php
curl.exe -I https://woo-logistics-demo.northendtech.ca/wp-admin/
```

Expected results:

- Site root returns `200 OK`.
- `/wp-login.php` returns `200 OK`.
- `/wp-admin/` redirects to login when unauthenticated.
- Logs include:
  - `Success: WordPress installed successfully.`
  - `Plugin 'woocommerce' activated.`
  - `Plugin 'woo-logistics-plugin' activated.`

## Known Pitfalls

- Missing Railway TXT verification can make the custom domain return Railway edge fallback responses such as `404`.
- Using copied DB values is more brittle than Railway reference variables.
- Using the public MySQL proxy inside Railway can introduce TLS/client issues. Prefer private Railway networking from service to service.
- Starting WP-CLI before `/var/www/html/wp-config.php` exists causes `Error: 'wp-config.php' not found.`
- No `/var/www/html` Railway volume means WordPress UI/file changes are not durable across rebuilds. This is acceptable for a quick demo but not for a persistent client site.

## Persistent Demo Recommendation

For a longer-lived client demo, add a Railway volume to the `wordpress` service at:

```text
/var/www/html
```

This matches the Railway WordPress/WooCommerce template style and preserves uploaded files, installed plugin files, and WordPress-generated state across deploys. The current demo database is already persisted through the MySQL volume.
