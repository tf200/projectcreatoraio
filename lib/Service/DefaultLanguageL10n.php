<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCA\ProjectCreatorAIO\AppInfo\Application;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Server;

/**
 * Translators for this app.
 *
 * Content that is created once and then shared by everyone in a project
 * (default folders, Deck stacks and cards, calendar names, automatic notes)
 * must not depend on the language of whoever happened to trigger it, so it
 * uses the server default language (`default_language` in config.php).
 */
final class DefaultLanguageL10n
{
	private ?IL10N $l = null;
	/** @var IL10N[]|null */
	private ?array $all = null;

	public function __construct(
		private readonly IFactory $factory,
		private readonly IConfig $config,
	) {
	}

	/** Translator in the server default language, for shared default content. */
	public function get(): IL10N
	{
		if ($this->l === null) {
			$language = $this->config->getSystemValueString('default_language', 'en');
			$this->l = $this->factory->get(Application::APP_ID, $language !== '' ? $language : 'en');
		}
		return $this->l;
	}

	/**
	 * Translators for every language this app ships, plus the server default.
	 * Use these to recognise default content (folder, stack or card names) that
	 * may have been created in another language than today's default.
	 *
	 * @return IL10N[]
	 */
	public function all(): array
	{
		if ($this->all === null) {
			$this->all = [$this->get()];
			foreach ($this->factory->findAvailableLanguages(Application::APP_ID) as $language) {
				$this->all[] = $this->factory->get(Application::APP_ID, $language);
			}
		}
		return $this->all;
	}

	/**
	 * Fallback for classes constructed without dependency injection (unit tests):
	 * the app translator in the current user's language.
	 */
	public static function userL10n(): IL10N
	{
		return Server::get(IFactory::class)->get(Application::APP_ID);
	}

	/** Fallback for classes constructed without dependency injection (unit tests). */
	public static function instance(): self
	{
		return Server::get(self::class);
	}
}
