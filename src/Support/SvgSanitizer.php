<?php

declare(strict_types=1);

namespace Stolic\AiCaptioning\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Stolic\AiCaptioning\Exceptions\UnsafeSvg;

/**
 * Makes an untrusted SVG (e.g. a logo harvested from a website) safe to rasterize.
 *
 * SVG renderers (librsvg, ImageMagick's MSVG) follow references: an <image href="file:///etc/passwd">,
 * an http URL, an XInclude or an XML entity can pull local files or remote content into the output image.
 * This keeps the drawing and removes everything that can reach outside the document:
 *  - a DOCTYPE or entity declaration is refused (no entity substitution, no network: LIBXML_NONET);
 *  - <script>, <foreignObject>, XInclude, animation and embedding elements, processing instructions are removed;
 *  - on* event-handler attributes and xml:base are removed;
 *  - href / xlink:href / src keep only internal '#fragment' references and data:image/(png|jpeg|gif|webp);base64 URIs;
 *  - url(...) in attributes and styles keeps only url(#fragment); @import is removed.
 * Anything that cannot be parsed safely throws UnsafeSvg. Render only from the returned string
 * (e.g. Imagick::readImageBlob), never from the original path or URL.
 */
final class SvgSanitizer
{
    /**
     * Elements removed with their content.
     */
    private const array REMOVED_ELEMENTS = [
        'script', 'foreignobject', 'iframe', 'embed', 'object', 'audio', 'video', 'canvas',
        'include', 'fallback', 'handler', 'listener', 'set', 'animate', 'animatemotion',
        'animatetransform', 'animatecolor', 'discard',
    ];

    private const string XINCLUDE_NAMESPACE = 'http://www.w3.org/2001/XInclude';

    private const string SAFE_DATA_URI = '~^data:image/(png|jpe?g|gif|webp);base64,[a-z0-9+/=\s]*$~i';

    public static function sanitize(string $svg): string
    {
        $svg = preg_replace('/^\xEF\xBB\xBF/', '', $svg) ?? $svg;

        if (trim($svg) === '') {
            throw UnsafeSvg::because('it is empty');
        }

        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $svg) === 1) {
            throw UnsafeSvg::because('it declares a DOCTYPE or entities');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false || $document->documentElement === null) {
            throw UnsafeSvg::because('it is not well-formed XML');
        }

        if ($document->doctype !== null) {
            throw UnsafeSvg::because('it declares a DOCTYPE or entities');
        }

        if (strtolower($document->documentElement->localName ?? '') !== 'svg') {
            throw UnsafeSvg::because('the root element is not <svg>');
        }

        self::removeUnsafeNodes($document);

        foreach (iterator_to_array((new DOMXPath($document))->query('//*') ?: []) as $element) {
            if ($element instanceof DOMElement) {
                self::cleanElement($element);
            }
        }

        $output = $document->saveXML($document->documentElement);

        if ($output === false) {
            throw UnsafeSvg::because('it could not be serialized');
        }

        return $output;
    }

    private static function removeUnsafeNodes(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        $remove = [];

        foreach ($xpath->query('//processing-instruction() | //*') ?: [] as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $remove[] = $node;

                continue;
            }

            if ($node instanceof DOMElement && (
                in_array(strtolower($node->localName ?? ''), self::REMOVED_ELEMENTS, true)
                || $node->namespaceURI === self::XINCLUDE_NAMESPACE
            )) {
                $remove[] = $node;
            }
        }

        foreach ($remove as $node) {
            /** @var DOMNode $node */
            $node->parentNode?->removeChild($node);
        }
    }

    private static function cleanElement(DOMElement $element): void
    {
        /** @var list<DOMAttr> $attributes */
        $attributes = iterator_to_array($element->attributes ?? []);

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute->localName ?? $attribute->name);
            $value = (string) $attribute->value;

            $unsafe = match (true) {
                str_starts_with($name, 'on') => true,
                $name === 'base' && $attribute->prefix === 'xml' => true,
                in_array($name, ['href', 'src'], true) => ! self::isSafeReference($value),
                $name === 'style' => false,
                default => ! self::hasOnlyFragmentUrls($value),
            };

            if ($unsafe) {
                $element->removeAttributeNode($attribute);
            } elseif ($name === 'style') {
                $css = self::sanitizeCss($value);

                if ($css === '') {
                    $element->removeAttributeNode($attribute);
                } else {
                    $attribute->value = $css;
                }
            }
        }

        if (strtolower($element->localName ?? '') === 'style') {
            $css = self::sanitizeCss((string) $element->textContent);

            while ($element->firstChild !== null) {
                $element->removeChild($element->firstChild);
            }

            if ($css !== '') {
                $element->appendChild($element->ownerDocument->createTextNode($css));
            }
        }
    }

    private static function isSafeReference(string $value): bool
    {
        $value = trim($value);

        return str_starts_with($value, '#') || preg_match(self::SAFE_DATA_URI, $value) === 1;
    }

    private static function hasOnlyFragmentUrls(string $value): bool
    {
        if (stripos($value, 'url') === false) {
            return true;
        }

        if (str_contains($value, '\\')) {
            return false;
        }

        preg_match_all('/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/is', $value, $matches);

        foreach ($matches[2] as $url) {
            if (! str_starts_with(trim($url), '#')) {
                return false;
            }
        }

        return preg_match_all('/url/i', $value) === count($matches[2]);
    }

    /**
     * Removes @import and every url(...) that is not a #fragment; drops the whole style when it uses CSS escapes
     * or image-set() (ways around those rules).
     */
    private static function sanitizeCss(string $css): string
    {
        if (str_contains($css, '\\') || stripos($css, 'image-set') !== false || stripos($css, 'expression') !== false) {
            return '';
        }

        $css = preg_replace('/@import[^;]*;?/i', '', $css) ?? '';
        $css = preg_replace_callback(
            '/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/is',
            static fn (array $match): string => str_starts_with(trim($match[2]), '#') ? $match[0] : 'none',
            $css,
        ) ?? '';

        return preg_match('/url\s*\((?!\s*[\'"]?#)/i', $css) === 1 ? '' : trim($css);
    }
}
