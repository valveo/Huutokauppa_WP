# Contributing

Thanks for looking at Huutokauppa. Bug reports, ideas and pull requests are welcome.

## Before you start

Small, focused fixes get reviewed fastest. For a larger change, open an issue first so the
direction can be agreed before you spend the time.

Do **not** report security problems in a public issue or pull request. Follow
[SECURITY.md](SECURITY.md).

## Setting up

You need a disposable WordPress site (6.4 or newer, PHP 8.1 or newer, MySQL or MariaDB) with
[WP-CLI](https://wp-cli.org/). Symlink or copy this repository to
`wp-content/plugins/huutokauppa`, activate the plugin and run the tests:

```
wp plugin activate huutokauppa
wp eval-file wp-content/plugins/huutokauppa/tests/run-tests.php
```

The tests create and delete their own users and items, but do not run them on a site with real
data.

## Guidelines

- Follow the [WordPress coding standards](https://developer.wordpress.org/coding-standards/).
  Code, comments and commit messages are in English.
- All user-visible text goes through the `huutokauppa` text domain. When you add or change
  strings, regenerate `languages/huutokauppa.pot` with
  `wp i18n make-pot . languages/huutokauppa.pot --exclude=tests`. Finnish and Swedish
  translations are welcome but not required.
- Use prepared statements for every database query, check nonces and capabilities on every form
  and action, and escape all output.
- A change that fixes a bug should come with a test that fails without the fix. Anything that
  touches bidding, automatic bids or end times needs extra care: say in the pull request what you
  changed and how you checked it.
- Templates in `templates/` can be overridden by themes, so treat their variables as a public
  interface and do not rename or remove them without a good reason.

## Licensing of contributions

Huutokauppa is licensed to the public under the
[PolyForm Noncommercial License 1.0.0](LICENSE). The author also offers the plugin under separate
commercial licenses. To keep that possible, contributions are accepted only on the following
terms.

By submitting a contribution (a pull request, patch, or code or text in an issue) you agree that:

1. You wrote the contribution yourself, or otherwise have the right to submit it under these
   terms, and it does not include code that you are not allowed to license this way.
2. You keep the copyright in your contribution. You grant Matti Mieskonen
   ([@valveo](https://github.com/valveo)) and anyone he transfers the project to a perpetual,
   worldwide, non-exclusive, royalty-free and irrevocable license to use, copy, modify,
   distribute, sublicense and relicense your contribution, under the project's license or under
   any other terms, **including commercial licenses**, without further permission from you and
   without payment.
3. You grant the same parties a matching license to any patents you hold that your contribution
   would otherwise infringe.
4. You are not expected to provide support for your contribution, and it is provided "as is",
   without warranty.

To confirm this, add the following line to each of your commit messages (`git commit -s` does it
for you):

```
Signed-off-by: Your Name <your.email@example.com>
```

Pull requests without it cannot be merged. If you contribute on behalf of an employer, make sure
your employer allows you to agree to these terms.

Keep any `Required Notice:` lines you find in the files you touch.

## Commercial use

The license allows noncommercial use only. To use Huutokauppa commercially, contact the author:
matti.mieskonen@gmail.com.
