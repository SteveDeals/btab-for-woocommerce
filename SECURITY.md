# Reporting a security problem

**Email developers@btab.app.** Please do not open a public issue for a security
problem — an issue is readable by everyone, including on shops that have not
updated yet.

Tell us what you can: what the problem is, how to reproduce it, which version
you found it in, and what an attacker could do with it. A rough report with a
reproduction beats a polished one without.

## What we will do

| | |
|---|---|
| Acknowledge your report | within 2 working days |
| Tell you whether we can reproduce it, and our plan | within 5 working days |
| Release a fix, or explain why it is not one | as fast as the problem deserves |

We will credit you in the changelog unless you would rather we did not.

## What is in scope

This plugin's code, and anything it does on a shop that installs it:

- the settings screen and how the publishable key is stored
- the import, including the photos it downloads into the Media Library
- the enquiry form and anything else it renders on a buyer-facing page
- the release zip and the workflow that builds it

## What is not in scope here

- **btab's API and dashboard.** Same address, `developers@btab.app`, but say
  which it is so it reaches the right people.
- **WordPress or WooCommerce itself.** Report those to
  [WordPress's HackerOne programme](https://hackerone.com/wordpress) and to
  [WooCommerce](https://woocommerce.com/).
- A shop running an outdated plugin, WordPress or PHP. Please tell the shop.

## Two things worth knowing before you report

- **The plugin holds no secret of btab's.** It works only with a publishable
  key that a shop makes in its own dashboard and can revoke there. A
  publishable key reads a shop window, builds carts and opens a checkout
  session; it cannot read buyers or orders and cannot change a store.
- **A store API key (`btab_live_…`) is refused** before it is ever sent,
  because that key does not belong in a shop. If you find a way to make the
  plugin send one, that is a report we want.

## Supported versions

While the plugin is below 1.0, only the latest release is supported. Upgrade
before reporting, if you can.
