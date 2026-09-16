# ScandiPWA WishlistGraphQl

Fork of [scandipwa/wishlist-graphql](https://github.com/scandipwa/wishlist-graphql) 2.0.17, maintained by Selveq for Magento 2.4.9 and PHP 8.3. Module name and namespace are unchanged, and the package replaces `scandipwa/wishlist-graphql` at every version, so it installs as a drop-in replacement. Selveq is not affiliated with or endorsed by Scandiweb.

## What it does

- Serves the wish list the ScandiPWA theme drives: `s_saveWishlistItem`, `s_removeProductFromWishlist`, `s_clearWishlist` and `s_moveWishlistToCart` as mutations, and `s_wishlist` to read a list — the caller's own, or somebody else's by its sharing code, which copies the items to the cart rather than emptying the list, because only its owner's own request empties it.
- Adds the fields the theme reads to Magento's wish list types: the list's `id` and `creators_name`, and an item's `sku`, `price` and `price_without_tax` at that item's own quantity, `buy_request` and `options`. `s_saveWishlistItem` answers the saved item's `id` and `sku`, and clients read the rest through `s_wishlist`.
- Carries file-type customizable options into the list as base64 uploads — narrowed to the extensions the product option declares, bounded by the platform's maximum file size and stored under a generated name — and re-submits them to the cart with the secret key Magento validates against the stored file.
- Shares a list by email through `s_shareWishlist`, bounded by the email allowance and message length under Stores > Configuration > Customers > Wish List, and sent from the store's own sender identity rather than the customer's address.

## Install

```sh
composer require selveq/wishlist-graphql
bin/magento setup:upgrade
```

## License

[OSL-3.0](LICENSE), the license of the original work. Scandiweb's copyright notices are kept in every file, and each file Selveq changed carries a `Modifications © Selveq` notice.
