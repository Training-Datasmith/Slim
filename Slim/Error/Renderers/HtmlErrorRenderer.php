<?php

/**
 * Slim Framework (https://slimframework.com)
 *
 * @license https://github.com/slimphp/Slim/blob/4.x/LICENSE.md (MIT License)
 */

declare(strict_types=1);

namespace Slim\Error\Renderers;

use function get_class;
use function htmlentities;
use function htmlspecialchars;

use Slim\Error\AbstractErrorRenderer;

use function sprintf;

use Throwable;

/**
 * Default Slim application HTML Error Renderer
 */
class HtmlErrorRenderer extends AbstractErrorRenderer
{
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {
        if ($displayErrorDetails) {
            $html = '<p>The application could not run because of the following error:</p>';
            $html .= '<h2>Details</h2>';
            $html .= $this->renderExceptionFragment($exception);
        } else {
            $html = '<p>' . htmlspecialchars($this->getErrorDescription($exception), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';
        }

        return $this->renderHtmlBody($this->getErrorTitle($exception), $html);
    }

    private function renderExceptionFragment(Throwable $exception): string
    {
        $html = sprintf('<div><strong>Type:</strong> %s</div>', htmlspecialchars(get_class($exception), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $code = $exception->getCode();
        $html .= sprintf('<div><strong>Code:</strong> %s</div>', htmlspecialchars((string) $code, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $html .= sprintf('<div><strong>Message:</strong> %s</div>', htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $html .= sprintf('<div><strong>File:</strong> %s</div>', htmlspecialchars($exception->getFile(), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $html .= sprintf('<div><strong>Line:</strong> %s</div>', $exception->getLine());

        $html .= '<h2>Trace</h2>';

        return $html . sprintf('<pre>%s</pre>', htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function renderHtmlBody(string $title = '', string $html = ''): string
    {
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return sprintf(
            '<!doctype html>' .
            '<html lang="en">' .
            '    <head>' .
            '        <meta charset="utf-8">' .
            '        <meta name="viewport" content="width=device-width, initial-scale=1">' .
            '        <title>%s</title>' .
            '        <style>' .
            '            body{margin:0;padding:30px;font:12px/1.5 Helvetica,Arial,Verdana,sans-serif}' .
            '            h1{margin:0;font-size:48px;font-weight:normal;line-height:48px}' .
            '            strong{display:inline-block;width:65px}' .
            '        </style>' .
            '    </head>' .
            '    <body>' .
            '        <h1>%s</h1>' .
            '        <div>%s</div>' .
            '        <a href="#" onclick="window.history.go(-1)">Go Back</a>' .
            '    </body>' .
            '</html>',
            $escapedTitle,
            $escapedTitle,
            $html
        );
    }
}
