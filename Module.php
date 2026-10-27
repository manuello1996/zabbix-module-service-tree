<?php declare(strict_types = 1);
 
namespace Modules\ModTreeService;
 
use APP;
use Modules\ModTreeService\Actions\CControllerTreeService;
use CControllerTabFilterProfileUpdate;
use CController as CAction;
use CRoleHelper;
use CWebUser;
 
class Module extends \Zabbix\Core\CModule {
	/**
	 * Initialize module.
	 */
	public function init(): void {
		if (!CWebUser::checkAccess(CRoleHelper::UI_SERVICES_SERVICES)) {
			return;
		}
		// Initialize main menu (CMenu class instance).
		APP::Component()->get('menu.main')
			->findOrAdd(_('Services'))
				->getSubmenu()
					->insertAfter('Services', (new \CMenuItem(_('Services tree')))
						->setAction('treeservice.view')
					);
	}
 
	/**
	 * Event handler, triggered before executing the action.
	 *
	 * @param CAction $action  Action instance responsible for current request.
	 */
	public function onBeforeAction(CAction $action): void {
		CControllerTabFilterProfileUpdate::$namespaces[CControllerTreeService::FILTER_IDX]
			= CControllerTreeService::FILTER_FIELDS_DEFAULT;
	}
 
	/**
	 * Event handler, triggered on application exit.
	 *
	 * @param CAction $action  Action instance responsible for current request.
	 */
	public function onTerminate(CAction $action): void {
	}
}
?>
