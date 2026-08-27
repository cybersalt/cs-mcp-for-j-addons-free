<?php

declare(strict_types=1);

namespace Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Extension;

\defined('_JEXEC') or die;

use Cybersalt\Component\Csmcpforj\Administrator\MCP\Event\RegisterToolsEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

/**
 * Page Builder CK MCP add-on. Verified against Light 3.6.5 on Joomla 6.1.2.
 *
 * One add-on covers both editions. Light and Pro are the SAME codebase — same
 * `com_pagebuilderck` element, same version number, same five tables. The only
 * difference is a `<ckpro>` flag in the manifest and the presence of a `pro`
 * directory, so there is nothing to fork at the data layer.
 *
 * Everything here works on the free Light build. Capabilities that genuinely do
 * not exist in Light — export/import, the block library, presets — ship in the
 * separate Pro add-on and additionally refuse unless the vendor's own `pro`
 * directory is present. Nobody is asked to pay us for access to an extension
 * they already have for free, and we do not route around another vendor's
 * paywall.
 *
 * Four findings shaped the design:
 *
 *   1. CONTENT IS RAW HTML, NOT JSON. A page is markup in a `htmlcode` column,
 *      and every block option is an HTML ATTRIBUTE on an empty sibling
 *      `<div class="ckprops">`. There is no encoder to normalise what we write,
 *      so the parser contract is load-bearing — see PagebuilderckContentTrait.
 *      Two of simple_html_dom's DEFAULT arguments corrupt real stored pages:
 *      `$lowercase` rewrites the `viewBox` in shipped SVG icons, and `$stripRN`
 *      eats inter-block whitespace.
 *
 *   2. A MISSING ADDON FAILS OPEN, NOT BLANK. This is the opposite of SP Page
 *      Builder. When no plugin provides a block's `data-type`, `renderElement()`
 *      returns '' and `replaceElement()` falls through to the raw inner markup,
 *      which renders as static HTML with its CSS still applied — no error, no
 *      log line. The rendered page therefore cannot tell you an addon is
 *      missing, so the tools cross-check `data-type` against enabled plugins and
 *      report it. They warn rather than refuse, because the content is not
 *      broken, only inert.
 *
 *   3. THE EDITOR DESTROYS OUT-OF-BAND COLUMN WRITES. `controllers/page.php`
 *      hardcodes alias, ordering, state, catid, created_by and access on every
 *      save. Any tool that writes those says so in its response.
 *
 *   4. THERE IS NO `styles` COLUMN. The admin model reads and writes one, but
 *      it does not exist and the write is silently dropped. The real page-to-
 *      style association is a comma-separated id list in
 *      `div.pagebuilderckparams[data-styles]` inside `htmlcode`.
 *
 * v1.0.0 ships 30 tools across 7 categories.
 */
final class Csmcpforjpagebuilderckfree extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	protected $autoloadLanguage = true;

	private const TOOLS = [
		// Pages — rows in #__pagebuilderck_pages.
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Pages\ListPagesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Pages\GetPageTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Pages\CreatePageTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Pages\UpdatePageTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Pages\DeletePageTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Pages\SetPageStateTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Pages\DuplicatePageTool::class,

		// Content — the htmlcode block tree. The core value of this add-on.
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Content\GetPageOutlineTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Content\GetBlockTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Content\ValidateContentTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Content\WritePageContentTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Content\AddBlockTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Content\UpdateBlockTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Content\MoveBlockTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Content\DeleteBlockTool::class,

		// Structure — rows and columns, whose geometry lives in four places at
		// once and collapses if they disagree.
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Structure\AddRowTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Structure\SetRowColumnsTool::class,

		// Styles — #__pagebuilderck_styles, plus the data-styles link.
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Styles\ListStylesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Styles\GetStyleTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Styles\GetPageStylesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Styles\SetPageStylesTool::class,

		// Reference reads — categories, saved elements, fonts.
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Reference\ListCategoriesTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Reference\ListElementsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Reference\GetElementTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Reference\ListFontsTool::class,

		// Backups — Page Builder CK writes a .pbck snapshot on every save, in
		// Light as well as Pro. That gives us version history for free.
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Backups\ListBackupsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Backups\RestoreBackupTool::class,

		// Diagnostics — orientation and a site-wide audit.
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Diagnostics\GetComponentInfoTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Diagnostics\ListAddonsTool::class,
		\Cybersalt\Plugin\System\Csmcpforjpagebuilderckfree\Tools\Diagnostics\CheckHealthTool::class,
	];

	public static function getSubscribedEvents(): array
	{
		return [RegisterToolsEvent::EVENT_NAME => 'onRegisterTools'];
	}

	public function onRegisterTools(RegisterToolsEvent $event): void
	{
		$registry = $event->getRegistry();
		$db       = $this->getDatabase();

		foreach (self::TOOLS as $toolClass) {
			$registry->register(new $toolClass($db));
		}
	}

	public static function getToolClasses(): array
	{
		return self::TOOLS;
	}
}
