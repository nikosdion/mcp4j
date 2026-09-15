<?php
/**
 * @package   mcp4j
 * @copyright Copyright (c)2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\Mcp4j\Administrator\Mapper\Panopticon;

defined('_JEXEC') || die;

/**
 * Output field whitelists for the Panopticon Connector's `templatechanged` (template override) tools.
 *
 * Copied from the connector's `component/api/src/View/Templatechanged/JsonapiView.php`
 * `$fieldsToRenderList`/`$fieldsToRenderItem`, **deliberately without** `overridePath`/`corePath`
 * (absolute server filesystem paths) and `diff` (never actually computed by the connector's model — its
 * item always contains the raw override/core source instead) — see `.plans/14-panopticon-core.md`
 * "Sensitive output".
 *
 * Pure: no Joomla.
 *
 * @since  0.0.1
 */
final class TemplateOverrideMapper
{
	public const LIST_FIELDS = ['id', 'template', 'hash_id', 'extension_id', 'state', 'action', 'client_id', 'created_date', 'modified_date'];

	public const ITEM_FIELDS = ['id', 'template', 'client', 'name', 'overridePathRelative', 'overrideSource', 'corePathRelative', 'coreSource'];

	public const INT_FIELDS = ['id', 'extension_id', 'state', 'client_id', 'client'];
}
