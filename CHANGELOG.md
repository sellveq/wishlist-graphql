# Changelog

## 3.0.0

Forked from `scandipwa/wishlist-graphql` 2.0.17. Module name and namespace are unchanged, and the package replaces `scandipwa/wishlist-graphql` at every version, so it installs as a drop-in replacement.

- A wish list reached by its sharing code is now copied to the cart, not moved: only its owner's own request empties it, as core's `ItemCarrier` has always done.
- `s_moveWishlistToCart` no longer fails for a signed-in customer whose cart does not exist yet; the cart is created.
- A wish list item that does not exist and one belonging to another customer now answer the same way, and neither is an internal error.
- The wish list queries refuse a guest and honour the "wish lists enabled" setting again.
- Sharing a wish list enforces the limits the storefront enforces: the email allowance, the message length, Magento's message validator, and every address checked before the first message is sent.
- Share emails are sent from the store's own sender identity, not from the customer's own address.
- A customer who has never saved an item can share a wish list; it used to answer with an error.
- The share email's item table is rendered again.
- Customizable-option file uploads take no part of their storage path from the client: the submitted name is reduced to a display title and checked against the extensions the product option declares, the bytes are written under a generated name, and an upload that is oversize or carries no usable name is refused.
- A file option saved to a wish list can now be moved into the cart: its secret key is the one Magento derives from the stored file, where the generated file name was kept before and never matched.
- `buy_request` no longer reports a file option's absolute server path.
- `s_wishlist` is marked non-cacheable, as the equivalent Magento query is.
- `price` and `price_without_tax` are the item's price at the item's own quantity, so a tier price that starts at two is what a two-item line reports.
- Bundle options record one selection and one quantity per option, so a bundle item added with a custom quantity keeps it.
- A bundle option's selected-option payload is validated as the entered-option payload already was, so a short payload raises the modelled error instead of writing null into the buy request.
- Adding to the cart from a wish list reports which product failed instead of a JSON blob of internal error text.
- `s_saveWishlistItem` populates the `id` and `sku` it is declared to return, which were always null.
- Reading a wish list no longer fails with an internal error for an item that has no stored buy request.
- Configurable-variant SKUs are resolved in one lookup instead of one product load per item, and a variant that no longer resolves falls back to the parent SKU.
