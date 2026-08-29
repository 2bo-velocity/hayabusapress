# Contributing to HayabusaPress

日本語版は [CONTRIBUTING.ja.md](CONTRIBUTING.ja.md) をご覧ください。

Thanks for taking the time. Please read the **[Licensing](#licensing--what-you-agree-to)** section before
opening a pull request: contributing to HayabusaPress involves an explicit licence grant, and it is
better to know that up front than after you have written the code.

---

## Licensing — what you agree to

HayabusaPress is published under the **AGPLv3 with a Plugin & Theme Exception**, and — for those who
cannot comply with the AGPLv3 — under a separate **commercial licence** available from the copyright
holder. See [`LICENSE`](LICENSE).

That second option only exists while the copyright holder is able to license the whole codebase. So:

> **By submitting a contribution — a pull request, a patch, or a change in any other form — you agree
> to all of the following.**
>
> 1. **You wrote it, or you have the right to submit it.** The contribution is your original work, or
>    you have obtained permission to submit it under these terms. It contains no code taken from a
>    source whose licence forbids this.
> 2. **It is licensed under `AGPL-3.0-or-later`** — the same licence as the rest of HayabusaPress.
> 3. **You grant the copyright holder** (Yusuke Tsubonoue / 2bo-velocity) **a perpetual, worldwide,
>    non-exclusive, royalty-free and irrevocable right to use, reproduce, modify, sublicense and
>    distribute your contribution under any other licence as well, including proprietary commercial
>    licences.**
> 4. **You keep your copyright.** This is a licence grant, not a transfer of ownership. You remain
>    free to use your own contribution however you like.

Clause 3 is the one that matters, and it is worth being blunt about why it is there: without it, a
single merged contribution would leave part of the codebase licensable only under the AGPLv3, and the
commercial option could no longer be offered honestly.

**Please state your agreement in the pull request description:**

```text
I have read CONTRIBUTING.md and I agree to the contribution terms.
```

If you would rather not grant clause 3, that is entirely reasonable — please open an issue describing
the change instead. A good description is valuable on its own, and the change can be implemented
independently.

---

## Before you write code

**HayabusaPress is a subtractive project.** The point is not what it can do but what it refuses to do,
and a feature that is useful in isolation can still be wrong for this engine. Two consequences:

- **Open an issue first** for anything larger than a bug fix or a typo, and wait for a reply. It costs
  you one message and can save you a weekend.
- **Read the "Intentional Omissions" section of the [README](README.md)** first. The things listed
  there are absent on purpose and pull requests adding them will be declined.

### Hard rules

These are architectural commitments, not style preferences. A change that breaks one of them cannot be
merged regardless of how well it is written.

| Rule | Why |
|---|---|
| **No ORM** — plain PDO and raw SQL only | Query cost has to stay visible and predictable |
| **No Composer packages in the backend** | The engine must run on shared hosting with zero dependencies |
| **No `namespace`** | There is no PSR-4 autoloader by design |
| **No computation at request time** | Everything is precompiled on save. The single exception is public search (`/search?q=`) |
| **No JOIN on the public side** | Use the denormalised cache columns |
| **No frontend framework** (React, Vue, …) and no heavy template engine (Twig, Smarty, Blade) | The public pages are static HTML served by `readfile()` |
| **No CDN in production** | The admin CSS ships prebuilt; a public page makes no external request |
| **No XML-RPC, no public sign-up** | Attack surface that buys nothing |

If your change adds a database query, an external request, or any work to the rendering of a public
page, it is very likely to be declined — say in the issue why it cannot be done at save time instead.

---

## Coding conventions

- **PHP 8.0+.** Every PHP file starts with `declare(strict_types=1);`.
- **Every file carries the licence header**, in this shape:

  ```php
  <?php
  /**
   * HayabusaPress - Single .env parser (replacement for parse_ini_file()).
   *
   * SPDX-License-Identifier: AGPL-3.0-or-later
   * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
   * for third-party plugins and themes. See LICENSE for the full text.
   */
  declare(strict_types=1);
  ```

- **Naming.** Core classes use plain names (`Auth`, `CacheManager`, `RouterCompiler`); the `Hbp`
  prefix is used only where a bare name would collide with a PHP built-in (`HbpLocale`). Plugin
  classes (`HbpContactPlugin`), constants (`HBP_*`) and CSS classes (`hbp-*`) always carry it.
- **English is the language of the source.** Identifiers, comments and commit messages are English.
- **Never hard-code user-facing strings.** Use `{@ key @}` in templates and `HbpLocale::t()` in code,
  and add the key to the English catalogue `core/lang/admin_en.php` or `frontend_en.php` — English is
  the master and every other locale is checked against it. The fallback argument of `t()` is always
  English. Run `php core/cli.php lang:check --strict` before you push.
- **Match the surrounding code.** Comment density, structure and idiom vary a little between the
  compilers and the controllers; follow whatever the file you are editing already does.

### Database changes

The full schemas in `core/database/schema.{mysql,pgsql}.sql` are the single source of truth, and
**both drivers must be updated together** — MySQL and PostgreSQL are equally supported. A schema change
also needs a migration under `core/database/migrations/`; the checklist in that directory's
`README.md` lists every place that has to move in step.

---

## Verifying your change

There is no automated test suite. Verify by hand, on both database drivers if you touched SQL:

```bash
php core/cli.php db:install       # fresh schema + seed
php core/cli.php system:init      # regenerate every cache
php core/cli.php plugins:rebuild
php core/cli.php lang:check --strict
```

Then exercise the affected screens in the admin panel and the public site. For anything that touches
caching, confirm that a warm public page still serves from the full-page cache — a request that
reaches the database on the second hit is a regression even when the page looks correct.

Describe in the pull request what you actually ran. "Tested on MySQL 8.4 and PostgreSQL 16, admin and
front end" is worth more than a green checkmark nobody configured.

---

## Pull requests

- **One change per pull request.** Unrelated fixes bundled together are hard to review and harder to
  revert.
- **Explain the why, not only the what.** The diff already shows what changed.
- **Include the agreement line** from the [Licensing](#licensing--what-you-agree-to) section.

---

## Reporting a security vulnerability

**Please do not open a public issue.** Email **support@2bo-velocity.com** with the details and, if you
can, a minimal reproduction. You will get an acknowledgement, and credit in the release notes if you
would like it.

---

## Bugs, questions and ideas

Open an issue on GitHub: <https://github.com/2bo-velocity/hayabusapress/issues>. For a bug, include the
PHP version, the database engine and version, the web server, and the exact steps that produce it.
