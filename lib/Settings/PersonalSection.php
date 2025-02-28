<?php
namespace OCA\Dropbox\Settings;

use OCP\IURLGenerator;
use OCP\IL10N;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {

	/** @var IL10N */
	private $l;

	/** @var IURLGenerator */
	private $urlGenerator;

	public function __construct(string $appName,
				    IURLGenerator $urlGenerator,
				    IL10N $l
				    ) {
		$this->appName = $appName;
		$this->l = $l;
		$this->urlGenerator = $urlGenerator;
	}

	/**
	 * returns the ID of the section. It is supposed to be a lower case string
	 *
	 * @returns string
	 */
	public function getID(): string {
		return 'migration'; //or a generic id if feasible
	}

	/**
	 * returns the translated name as it should be displayed, e.g. 'LDAP / AD
	 * integration'. Use the L10N service to translate it.
	 *
	 * @return string
	 */
	public function getName(): string {
		return $this->l->t('Data migration');
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of
	 * the settings navigation. The sections are arranged in ascending order of
	 * the priority values. It is required to return a value between 0 and 99.
	 */
	public function getPriority(): int {
		return 80;
	}

	/**
	 * @return ?string The relative path to a an icon describing the section
	 */
	public function getIcon(): ?string {
		return $this->urlGenerator->imagePath('core', 'actions/download.svg');
	}

}