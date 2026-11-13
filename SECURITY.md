# Security policy

## Reporting a vulnerability

Please do not open a public issue for a security problem. Report it privately through GitHub's private vulnerability reporting: on this repository, open **Security → Advisories → Report a vulnerability** (https://github.com/Apistolic/stolic-ai-captioning-plugin/security/advisories/new).

Include what is affected, how to reproduce it, and the impact you see. We aim to acknowledge reports within a few working days and to publish a fix and an advisory once it is resolved.

## Supported versions

Only the latest release (and `main`) receives security fixes while the package is below 1.0.

## Untrusted images and SVG logos

The branding feature is built to handle images and logos from untrusted sources (for example logos harvested from websites):

- SVG logos are cleaned by `Stolic\AiCaptioning\Support\SvgSanitizer` before they are rendered: a DOCTYPE or entity declaration is refused (no entity substitution, no network access while parsing); `<script>`, `<foreignObject>`, XInclude, animation and embedding elements, processing instructions, event-handler attributes and `xml:base` are removed; `href` / `xlink:href` / `src` keep only internal `#fragment` references and `data:image/(png|jpeg|gif|webp)` URIs; `url(...)` keeps only `#fragment` references and `@import` is removed. An SVG that cannot be parsed safely is refused.
- SVGs are rendered only from the cleaned string, never from the original path or URL.
- Other source and logo files must start with the bytes of PNG, JPEG, GIF, WebP or AVIF/HEIC and are read as that format, so ImageMagick never chooses a coder (MVG, MSL, PostScript, text, ...) from file contents.

We still recommend a restrictive ImageMagick `policy.xml` on servers that process untrusted images (disable the coders and URL handlers you do not need, and set resource limits).

Host apps can reuse `SvgSanitizer::sanitize($svg)` wherever they handle untrusted SVGs.
