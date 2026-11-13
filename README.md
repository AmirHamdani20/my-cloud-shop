# GameGrid

GameGrid is a PHP game storefront for PlayStation, Xbox, Nintendo, PC, and other platforms. Product prices, stock, carts, wishlists, and orders remain in the existing SQL Server catalog.

## RAWG game lookup

The admin dashboard can search RAWG for game titles, platform metadata, and artwork URLs. Configure the API key as a server environment variable named `RAWG_API_KEY`; never put it in PHP, JavaScript, or a committed `.env` file.

RAWG search only provides game metadata and artwork. An admin must choose a title, set its platform, enter the store's price and stock, and add it to the catalog before customers can buy it. Use artwork in accordance with RAWG's terms and your rights to display it.

The RAWG lookup requires PHP cURL. If `RAWG_API_KEY` is not configured, the rest of the storefront remains available and the admin search reports that game search is unavailable.
