# Setup Instructions

## Backend (Lumen)
1. Point your domain `shopify.worldoftech.company` to the `public` directory of this project.
2. Copy `.env.example` to `.env`.
3. Fill in the following Shopify credentials:
   - `SHOPIFY_API_KEY`: From your Shopify Partner Dashboard.
   - `SHOPIFY_API_SECRET`: From your Shopify Partner Dashboard.
   - `SHOPIFY_APP_URL`: `https://shopify.worldoftech.company`
   - `SHOPIFY_APP_SCOPES`: `read_customers,write_customers,read_products,write_discounts`
4. Run `composer install`.
5. Run `php artisan migrate`.

## Shopify App Configuration
1. Go to your Shopify Partner Dashboard.
2. Select your app: **plymouthmedical**.
3. Update URLs:
   - **App URL**: `https://shopify.worldoftech.company/`
   - **Allowed Redirection URL**: `https://shopify.worldoftech.company/auth/callback`
4. Install the app on your test store: `plymouthmedical-2.myshopify.com`.

## Storefront Integration
1. **Liquid Snippet**:
   - Copy `extensions/custom-pricing-snippet/tiered-pricing.liquid` to your theme's `snippets` folder.
   - Include it in `layout/theme.liquid` or `sections/main-product.liquid` using `{% render 'tiered-pricing' %}`.
2. **Shopify Functions**:
   - Follow the instructions in `extensions/custom-pricing-function.md` to deploy the discount logic using the Shopify CLI:
     ```bash
     shopify app generate extension --template discount_discounts --name tiered-pricing
     ```
   - Copy the logic from the markdown file into the generated extension.
