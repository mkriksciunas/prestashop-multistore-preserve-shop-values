# PrestaShop multistore: preserve shop-specific product values

A focused core patch for PrestaShop 1.7.8.11. It prevents a product update made in **All shops** context from copying one shop's price and tax settings into every existing `product_shop` row.

## The issue

This appeared while activating or deactivating a product in the Back Office with the shop selector set to **All shops**. The product's status changed, but shops that had their own prices and tax rules lost those values.

The activation path calls `AdminProductDataUpdater::activateProductIdList()`, which loads a `Product` object and calls `$product->update()`. In `ObjectModel::update()`, PrestaShop builds the product's shop fields with `getFieldsShop()`. In All shops context, it then applies those fields to each shop row in `product_shop`.

The object is loaded with one shop's values, while the update targets multiple shops. That can copy the loaded shop's `price`, `id_tax_rules_group`, and `wholesale_price` over the shop-specific values for every targeted shop.

## The fix

In `classes/ObjectModel.php`, filter those columns from the shop-field payload for products when the context is All shops. Place this after `$fields = $this->getFieldsShop();` and before the product-shop update loop:

```php
if ($this->def['table'] === 'product'
    && Shop::getContext() === Shop::CONTEXT_ALL
) {
    unset(
        $fields['price'],
        $fields['id_tax_rules_group'],
        $fields['wholesale_price']
    );
}
```

The patch leaves the base `product` table update in place. It only prevents these three fields from being written to existing `product_shop` rows during an All shops update. Other product fields, including `active`, continue through the normal update path.

`wholesale_price` is included because this patch preserves per-shop wholesale pricing too. Remove that entry from the `unset()` call if wholesale price should be shared across shops.

## Effect and scope

- Product activation and deactivation can continue through `Product::update()` and its normal hooks.
- Existing shop-specific prices, tax rules groups, and (with the supplied snippet) wholesale prices are not overwritten by product updates in All shops context.
- These fields cannot be changed across all shops through this update path. Edit them in each shop's context where the shop-specific values should change.
- The condition applies to all product `ObjectModel::update()` calls in All shops context, not only status changes. Review other bulk product workflows before adopting it.

## Verify

Before and after changing a product's status in All shops context, compare the per-shop rows:

```sql
SELECT id_shop, active, price, id_tax_rules_group, wholesale_price
FROM ps_product_shop
WHERE id_product = 657627
ORDER BY id_shop;
```

Replace `ps_` with the installation's table prefix and use the relevant product ID. Confirm that `active` follows the requested status while each shop's price and tax fields retain their prior values. Also confirm that editing those values in a single-shop context still updates that shop.

## Compatibility and maintenance

This patch targets **PrestaShop 1.7.8.11** and modifies core code in `classes/ObjectModel.php`. It is not a module override. Core upgrades can replace the change, so recheck and reapply it when upgrading. Test on a staging copy with representative single-shop and multishop products before deploying.

## Repository purpose

A small, reviewable patch and explanation for preserving shop-specific product pricing and tax configuration during product updates from All shops context.
