# Legal templates for Enable Banking production submission

These two documents (`privacy.md`, `terms.md`) exist solely to satisfy Enable
Banking's production application requirement that `privacy_url` and `terms_url`
point at publicly-reachable pages (SPEC §9.5). They are scoped to Spendula's
single-user, self-hosted, AIS-only use case. They are not legal advice.

## Deployment

Spendula itself runs on a tailnet and is not publicly reachable. Enable
Banking validates the URLs from the public internet, so privacy and terms must
be hosted elsewhere. The recommended host is GitHub Pages.

1. Create a public repository: `spendula-legal` under your GitHub account.
2. Copy the three files in, preserving the file names:
   - `docs/legal/index.md`   → `index.md`   (landing page with links)
   - `docs/legal/privacy.md` → `privacy.md` (Privacy Policy)
   - `docs/legal/terms.md`   → `terms.md`   (Terms of Use)
3. In the new repo's settings, enable GitHub Pages on the default branch, root.
   No Jekyll config needed — the default theme renders all three files.
4. The resulting URLs (assuming GitHub user `dlucian`):
   - Landing: `https://dlucian.github.io/spendula-legal/`
   - Privacy: `https://dlucian.github.io/spendula-legal/privacy`
   - Terms:   `https://dlucian.github.io/spendula-legal/terms`
5. Submit the **privacy** and **terms** URLs in the Enable Banking
   production application form. The landing page is for humans browsing
   the repo; EB only needs the two policy URLs.

## When to re-edit

Update both documents (and bump the **Effective date**) whenever:

- The operator's contact email or country of residence changes
- The set of sub-processors changes (currently: Enable Banking, YNAB)
- Spendula starts processing data beyond AIS (e.g. payment initiation) — this
  would also require a new EB application
- Spendula is offered to anyone other than the operator (which is out of scope
  for v1; see SPEC §1)

After editing, copy the updated files into `spendula-legal` and push.
