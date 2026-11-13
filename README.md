# GameGrid

GameGrid is a PHP game storefront for PlayStation, Xbox, Nintendo, PC, and other platforms. Product prices, stock, carts, wishlists, and orders remain in the existing SQL Server catalog.

## Game artwork and catalog import

The admin dashboard can search RAWG for game titles and platform metadata. RAWG's `background_image` is not a box-cover image, so it is not automatically used for product artwork. Add a licensed case-cover URL when creating a game, or update an existing game's `image_url` from the admin cover list.

The downloadable `game-import-template.csv` contains six suggested games each for Xbox Series X|S, PlayStation 5, and Nintendo Switch. Fill in the store price, starting stock, and licensed cover URL for every row, then paste the CSV into the admin bulk-import form. Imports are atomic, limited to 50 games, and do not modify existing products. Do not delete existing catalog rows to replace their covers; update only their artwork URL.

Configure the RAWG API key as a server environment variable named `RAWG_API_KEY`; never put it in PHP, JavaScript, or a committed `.env` file. RAWG lookup requires PHP cURL. If the key is not configured, the rest of the storefront and manual cover-management tools remain available.
