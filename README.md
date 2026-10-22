# Huutokauppa – WordPress plugin

Stand-alone online auctions for WordPress, rebuilt from the original 2015
Huutokauppa PHP application. No WooCommerce or other plugins required.

Requires WordPress 6.4+, PHP 8.1+ and MySQL/MariaDB.

## Features

- **Auction items** (`hk_item` post type) with categories, image gallery,
  start/end time, start price, minimum raise, VAT note (VAT added / margin
  scheme), location, delivery and a public contact person.
- **Bidding** with the original rules: the smallest bid is the highest bid
  (or start price) plus the minimum raise; a bid in the last 5 minutes moves
  the end to 5 minutes after the bid (configurable).
- **Automatic bidding** ("korotusautomaatti"): the user sets a maximum and the
  plugin bids for them only as much as needed. The result is calculated
  directly instead of recording every intermediate step.
- Bids on one item are serialised with a MySQL lock, so two simultaneous bids
  can never both be accepted at the same price.
- **Live updates**: countdowns and prices refresh without reloading; bidding
  works through the REST API, with plain form posts as a fallback when
  JavaScript is not available.
- **Seller submissions**: logged-in users submit items with images; they wait
  for approval as *Pending*. Publishing fills in the schedule and e-mails the
  seller. Submissions can also be rejected.
- **Winner handling**: when an auction ends, an administrator accepts the
  highest bid (winner is e-mailed) or rejects it because the seller's lowest
  price was not reached (bidder is e-mailed). Optionally accept automatically.
  Auctions without bids close on their own. Administrators get an e-mail
  listing auctions waiting for a decision.
- **Accounts** use normal WordPress users plus phone, address, postcode, city,
  business ID and newsletter consent; e-mail activation; blocking users from
  bidding; failed-login lockout.
- **My bids** page: open auctions (leading/outbid), automatic bids, watch list
  and ended auctions.
- **CSV exports**: newsletter subscribers, users, results, bids per item.
- Finnish and Swedish translations included; per-item translated title,
  description and delivery text for extra languages (setting).
- Every front-end template can be overridden in the theme
  (`your-theme/huutokauppa/<template>.php`).

## Installation

1. Copy the `huutokauppa` folder to `wp-content/plugins/` and activate it.
2. Activation creates the tables and these pages (titles in the site language):

   | Page            | Shortcode                    |
   |-----------------|------------------------------|
   | Auctions        | `[huutokauppa]`              |
   | My bids         | `[huutokauppa_omat]`         |
   | Submit an item  | `[huutokauppa_ilmoita]`      |
   | Register        | `[huutokauppa_rekisteroidy]` |
   | My details      | `[huutokauppa_tiedot]`       |

   `[huutokauppa_kategoriat]` lists categories with live item counts (for a
   sidebar). `[huutokauppa show="upcoming|ended" category="slug"]` filters the
   list.
3. Review **Auctions → Settings** (minimum raise, extension time, VAT rate,
   notification e-mail, terms of use page, extra content languages).
4. For reliable end-of-auction e-mails, run WP-Cron from the system cron:
   add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php` and
   `*/5 * * * * curl -s https://example.com/wp-cron.php > /dev/null`.
   Bidding itself never depends on cron.
5. Make sure the site can send e-mail (an SMTP plugin is recommended).

Visitors use the normal WordPress login. The registration form works even
when "Anyone can register" is off in WordPress settings.

## Roles

- **Administrator** – everything.
- **Auction manager** (`hk_manager`) – manages items, categories, decisions
  and exports, but not site settings.
- Bidders and sellers are ordinary subscribers.

## Development

Tests run against a disposable WordPress site with the plugin active:

```
wp eval-file wp-content/plugins/huutokauppa/tests/run-tests.php
```

Translations: `languages/huutokauppa.pot` is generated with
`wp i18n make-pot . languages/huutokauppa.pot --exclude=tests`.

Uninstalling keeps auction data unless `HK_REMOVE_ALL_DATA` is defined as
`true` in `wp-config.php`.

## Differences from the old application

- Passwords, sessions and login are WordPress's own (bcrypt, secure cookies).
- All database access uses prepared statements.
- The "intra" and "admin" sections are replaced by the WordPress admin.
- Static pages (rules, privacy, contact) are ordinary WordPress pages.
- Bidders are shown anonymised in the public bid history ("Bidder 2").
- The auction fee calculation in the old item page was not displayed anywhere
  and is not included.

## License

[PolyForm Shield License 1.0.0](LICENSE), Copyright (c) 2026 Matti Mieskonen
([@valveo](https://github.com/valveo)).

You may use, modify and share the plugin for any purpose, **including running
your own auction website, for yourself or a client**, with one exception: you
may not use it to provide a product or service that competes with it, such as
selling the plugin or a derivative of it, or offering it as a hosted auction
platform for others. The precise terms are in the license. When you share the
software, keep the license text and the `Required Notice:` line in
[LICENSE](LICENSE).

This is a *source-available* license, not an OSI-approved open source license,
and it is not GPL-compatible, so the plugin cannot be listed in the
WordPress.org plugin directory. Security issues: see [SECURITY.md](SECURITY.md).
