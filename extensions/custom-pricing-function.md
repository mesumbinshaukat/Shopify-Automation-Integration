# Shopify Function: Custom Tiered Pricing Discount

This extension uses the `Shopify Functions` API to apply discounts based on the customer's assigned tier.

## Input Query (input.graphql)
```graphql
query Input {
  cart {
    buyerIdentity {
      customer {
        metafield(namespace: "custom_pricing", key: "discount_value") {
          value
        }
        metafield_type: metafield(namespace: "custom_pricing", key: "discount_type") {
          value
        }
      }
    }
    lines {
      id
      quantity
      merchandise {
        ... on ProductVariant {
          id
        }
      }
    }
  }
}
```

## Logic (index.js / main.rs)
The function reads the `discount_value` and `discount_type` from the customer metafields and applies a automatic discount to all line items in the cart.

```javascript
export function run(input) {
  const customer = input.cart.buyerIdentity?.customer;
  if (!customer || !customer.metafield?.value) {
    return { discounts: [] };
  }

  const discountValue = parseFloat(customer.metafield.value);
  const discountType = customer.metafield_type?.value || 'percentage';

  return {
    discounts: [
      {
        value: {
          [discountType === 'percentage' ? 'percentage' : 'fixedAmount']: discountValue
        },
        targets: [
          {
            cartLine: { id: "ALL" }
          }
        ],
        message: "Tiered Pricing Discount"
      }
    ]
  };
}
```
