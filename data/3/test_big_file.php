<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
				CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
					$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

            $itemsBrowser = $items->browse()
                ->setDefaultSortField('product_title', true)
                ->addColumn('Date', 'classification_date', true)
                ->setCSSClass('cms-first')
                ->setAlign('left')
                ->setWidth('62')
                ->addColumn('Calcs', 'api_calculations_count', true)
                ->setAlign('left')
                ->setWidth('45')
                ->addColumn('SKU', 'sku', true)
                ->setAlign('left')
                ->setWidth('50')
                ->addColumn('Description', 'product_title', true)
                ->setAlign('left')
                ->setWidth('178');

            $itemsBrowser->addColumn('Duty Category', 'duty_category', true)
                ->setAlign('left')
                ->setCSSClass('cms-category')
                ->setWidth('190');

            $this->template_vars['items_browser'] =
                $itemsBrowser->addColumn($searchBox)
                    ->setAlign('right')
                    ->setWidth('300')
                    ->setCSSClass('last')
                    ->addColumn($navigator)
                    ->setWidth('0')
                    ->setCSSClass('hidden')
                    ->setCustomParam('filter', $filter)
                    ->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
                    ->setPager($pager)
                    ->loadTemplate('front_catalog_management_system.tpl.php');
        }
    }

    private function approveSelectedItems($itemIds)
    {
        $items = json_decode(InPost('selected_item_ids'));
        foreach($items as $item)
        {
            try
            {
                $itemObject = BulkClassificationRequestItem::getById($item->itemID);

                $category = ProductCategory::getById($item->categoryID);
                $subCategory = ProductCategory::getById($item->subCategoryID);
                $productItem = ProductItem::getById($item->productItemID);

                $itemObject->setItemCategory($category, $subCategory, $productItem);
                $this->approveItem($itemObject);
            }
            catch(Exception $e)
            {
            }
        }
        die;
    }
    /**
     * @param BulkClassificationRequestItem $item
     * @throws Exception
     */
    protected function approveItem($item)
    {
        $productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
        $autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

        $bulkClassification = $item->getBulkClassificationRequest();
        if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
            && User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
        )
        {
            $item->setClassifiedByExpert($this->getUser(), true);
        }

        $item->set('unable_to_classify_reason', '')
            ->set('classification_date', date('Y-m-d H:i:s'))
            ->set('autoclassification_manually_changed', $autoClassificationChanged);

        $item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
        $item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
    }

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
			'action' => 'addToCatalog',
			'fileName' => $document->getName(),
			'countItems' => InCache('countItems', '', self::CACHE_ID),
			'apiKey' => $this->getApiKeyName(),
			'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
			'action' => 'uploadFile',
			'apiKey' => $this->getApiKeyName(),
			'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
			'fileHash' => $document->getHash(),
			'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}
<?php
require_once(CUSTOM_CLASSES_PATH . 'email_templates.php');
require_once(CONTROLS_PHP . 'front_categories_tree.php');
require_once(CONTROLS_PHP . 'front_sku_monitoring.php');
define('DEV_USER_EMAIL', 'development@itransition.com');

class CPageTemplate extends CBasicTemplate
{
	const CACHE_ID = 'cms';

	// 300 sec = 5 min
	const CRON_TIME_INTERVAL = 300;
	const TIME_FOR_ONE_ITEM_REQUEST = 0.3;
	/** @var BulkClassificationRequestItemsGroup */
	private $bulkClassificationRequestItemsGroup;

	/** @var User */
	protected $user;

	/** @var int */
	protected $subAccountId;

	/** @var bool */
	protected $ordersOnlyFilter;

	/** @var bool */
	protected $haveOnlyCalcsFilter;

	/** @var bool */
	protected $lowCertaintyFilter;

	/**@var CCategoriesTree */
	protected $categoriesTreeControl;

	function CPageTemplate(&$page)
	{
		parent::CBasicTemplate($page);
		$this->page = &$page;
		$this->page_params = $this->page->requestHandler->mPageParams;
		$this->page_url = $this->page->requestHandler->mPageUrl;
		$this->ematpl = new CEmailTemplates($page->Application);
		$this->user = User::getCurrent()->getBrokerSubAccountsMasterUser();

		$this->categoriesTreeControl = new CCategoriesTree($this);
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

		if(User::getCurrent()->isAdditionalUser() && !User::getCurrent()->getAdditionalUser()->hasPermToRct())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('home'));
			die;
		}
	}

	public function ajaxAddDutyCategoriesRestrictions()
	{
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		$this->categoriesTreeControl->addRestrictionsToSubAccount();
	}

	/**
	 * @return mixed|string
	 */
	public function getAction()
	{
		return InGetPost('action', false);
	}

	function on_page_init()
	{
		set_time_limit(600);
		ini_set('memory_limit', '4096M');

		$this->tv['cms_super_keywords_url'] = URL::getDirectURLBySystem('cms_super_keywords');
		$this->template_vars['status_classified'] = false;
		$this->template_vars['status_cant_classify'] = false;
		$this->template_vars['status_no_result'] = false;
		$this->template_vars['status_api_auto'] = false;
		$this->template_vars['duty_categories_popup'] = false;
		$this->template_vars['current_status'] = false;
		$this->template_vars['predefined_categories_amount'] = false;
		$this->template_vars['categories_js_data'] = false;
		$this->template_vars['js_data'] = false;
		$this->template_vars['added_categories_to_script'] = false;

		$client = $user = User::getCurrent();

		if($user->isAnonymous())
		{
			$client = Visitor::getCurrent();
		}

		/* @var $assignedPlan AssignedPlan */
		$assignedPlan = $client->getAssignedPlan();

		if(!$assignedPlan->isAPI())
		{
			$this->internalRedirect(Url::getDirectURLBySystem('compare_plans'));
		}

		/**
		 * @var $brokerSubAccount BrokerSubAccount
		 */
		$brokerSubAccounts = array();

		foreach(BrokerSubAccountsGroup::getAllForUser(User::getCurrent()) as $brokerSubAccount)
		{
			/* @var $brokerSubAccount BrokerSubAccount */
			$brokerSubAccounts[$brokerSubAccount->getId()] = $brokerSubAccount->getAccountName();
		}

		if(InCache('sub_account_id', null) === null)
		{
			reset($brokerSubAccounts);
			SetCacheVar('sub_account_id', key($brokerSubAccounts));
			$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		}

		CInput::set_select_data('account_or_website', $brokerSubAccounts);

		parent::on_page_init();
	}

	/**
	 * @return BrokerSubAccount
	 */
	public function getCurrentBrokerSubAccount()
	{
		if(!InCache('sub_account_id', false))
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		return BrokerSubAccount::getById(InCache('sub_account_id'));
	}

	function draw_body()
	{
		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
			$this->template_vars['account_or_website'] =  InCache('sub_account_id');
		}

		$this->template_vars['orders_only_checked'] = false;
		$this->template_vars['calcs_only_checked'] = false;
		$this->template_vars['low_certainty_checked'] = false;

		if(inPost('orders_only') == 'true')
		{
			SetCacheVar('orders_only', true);
		}

		if(inPost('orders_only') == 'false')
		{
			SetCacheVar('orders_only', false);
		}

		if(inPost('calcs_only') == 'true')
		{
			SetCacheVar('calcs_only', true);
		}

		if(inPost('calcs_only') == 'false')
		{
			SetCacheVar('calcs_only', false);
		}

		if(inPost('low_certainty') == 'true')
		{
			SetCacheVar('low_certainty', true);
		}

		if(inPost('low_certainty') == 'false')
		{
			SetCacheVar('low_certainty', false);
		}

		if($this->urlParams[0] == 'not_validated')
		{
			$this->lowCertaintyFilter = inCache('low_certainty');
		}

		$this->ordersOnlyFilter = inCache('orders_only');
		$this->haveOnlyCalcsFilter = inCache('calcs_only');

		$this->ordersOnlyFilter == true ? $this->template_vars['orders_only_checked'] = 'checked' : '';
		$this->haveOnlyCalcsFilter == true ? $this->template_vars['calcs_only_checked'] = 'checked' : '';
		$this->lowCertaintyFilter == true ? $this->template_vars['low_certainty_checked'] = 'checked' : '';

		if(!$this->subAccountId)
		{
			$this->subAccountId = BrokerSubAccount::getMasterByUser(User::getCurrent())->getId();
			SetCacheVar('sub_account_id', $this->subAccountId);
		}

		$this->template_vars['jobs_waiting'] = false;

		try
		{
			$jobsWaiting = CMSAutoClassificationsJobsGroup::getJobsBySubaccountAndStatuses($this->subAccountId,
					CMSAutoClassificationsJob::STATUS_WAITING)->getAmount();
			if($jobsWaiting > 0)
			{
				$this->template_vars['jobs_waiting'] = true;
			}
		}
		catch(Exception $e)
		{
		}

		$filter = arr_val($this->page_params, 0, '');

		$sku = InPostGet('sku_keyword', false);

		$itemInstance = new BulkClassificationRequestItem();
		$statusUrlPrefix = "";
		if($this->urlParams[0] == "all")
		{
			$categoryInfo['categoryId'] = $this->urlParams[2];
			$categoryInfo['categoryLevel'] = $this->urlParams[1];
			$statusUrlPrefix = "all/" . $this->urlParams[1] . "/" . $this->urlParams[2] . "/";
			switch($this->page_params[3])
			{
				case 'validated' :
					$filteredStatus = BulkClassificationRequestItem::$validatedCmsStatuses;
					break;
				case 'not_validated' :
					$filteredStatus = BulkClassificationRequestItem::$notValidatedCmsStatuses;
					break;
				case 'cant_classify' :
					$filteredStatus = BulkClassificationRequestItem::$cantClassifyCmsStatuses;
					break;
				default:
					$filteredStatus = $itemInstance->allCmsStatuses;
					break;
			}
			$filteredByCategory = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId, $filteredStatus, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter, $categoryInfo);
		}

		if($this->isAjaxRequest)
		{
			if($this->urlParams[0] == 'duty-categories')
			{
				$this->template_vars['ajax_action'] = 'duty_categories_popup';
				$this->categoriesTreeControl = new CCategoriesTree($this);
				$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());

				return;
			}
			if($this->urlParams[0] == 'sku-monitoring')
			{
				$this->template_vars['ajax_action'] = 'sku_monitoring_popup';
				$skuMonitoringControl = new CFrontSkuMonitoring($this);
				$skuMonitoringControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
				return;
			}
			if(inPost('action') == 'approve_selected')
			{
				$this->approveSelectedItems(inPost('selected_item_ids'));
			}

			if(inPost('action') == 'classify_selected')
			{
				$this->classifySelectedItems(inPost('selected_item_ids'));
			}

			$this->template_vars['url_for_title'] = false;
			$this->template_vars['url_for_description'] = false;
			$this->template_vars['info_message_id'] = false;

			if(InGet('action'))
			{
				$this->template_vars['action'] = InGet('action');
			}
			else
			{
				$this->template_vars['action'] = 'all';
			}

			$this->template_vars['ajax_action'] = 'navigator';

			$this->template_vars['remove_row'] = false;
			$this->template_vars['empty_row'] = false;

			if($this->template_vars['ajax_action'] == 'request_more_info'
					|| $this->template_vars['ajax_action'] == 'unable_to_classify'
			)
			{
				$this->template_vars['item_id'] = InGet('item_id');
			}

			if($action = InGet('action'))
			{

				$this->template_vars['ajax_action'] = 'proccess';

				try
				{

					$item = BulkClassificationRequestItem::getById(InGet('id', 1));

					$url = $item->getUrl();


					if($url)
					{

						$this->template_vars['url_for_title'] = $url;
						$this->template_vars['url_for_description'] = $url;
					}
					else
					{
						$subAccountName = trim(str_replace('Master', '',
								$item->getBrokerSubAccount()->getAccountName()));

						if(BrokerSubAccount::EXPERT_SUPERVISOR_SUBACCOUNT_NAME == $subAccountName
								|| BrokerSubAccount::EXPERT_SUBACCOUNT_NAME == $subAccountName
						)
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($item->get('description'));
						}
						else
						{
							$this->template_vars['url_for_title'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('title'));
							$this->template_vars['url_for_description'] = 'https://www.google.com/search?q=' . urlencode($subAccountName . ' ' . $item->get('description'));
						}
					}

					$date = $item->get('classification_date');
					$sku = $item->getSku();
					$skuColumn = '<span href="#" class="ico-help" onclick="return false;" title="' . implode('<br/>',
									$sku) . '">' . $sku[0] . '</span>';
					$titleColumn = $item->get('title');
					$descriptionColumn = $item->get('description');

					$this->template_vars['status_classified'] = BulkClassificationRequestItem::STATUS_CLASSIFIED;
					$this->template_vars['status_cant_classify'] = BulkClassificationRequestItem::STATUS_CANT_CLASSIFY;
					$this->template_vars['status_no_result'] = BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT;
					$this->template_vars['status_api_auto'] = BulkClassificationRequestItem::STATUS_API_AUTO_CLASSIFIED;
					$this->template_vars['date_column'] = date('d/m/y', strtotime($date));
					$this->template_vars['calcs_column'] = $item->getApiCalculationsCount();
					$this->template_vars['sku_column'] = nl2br($skuColumn);
					$this->template_vars['title_column'] = nl2br(htmlspecialchars($titleColumn));
					$this->template_vars['description_column'] = nl2br(htmlspecialchars((mb_strlen($descriptionColumn) > 500) ? substr($descriptionColumn,
									0, 500) . '...' : $descriptionColumn));
					$this->template_vars['item_id'] = $item->getId();

					if($item->getStatus() != BulkClassificationRequestItem::STATUS_DOWNLOADED || true)
					{
						switch(InGet('action'))
						{
							case 'to_no_result' :

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();
								CInput::set_select_data('product_category', array('' => 'Please Select'));
								CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
								CInput::set_select_data('product_item', array('' => 'Please Select'));
								$this->template_vars['remove_row'] = false;
								break;

							case 'to_edit_category' :
								$itemStatus = $item->getStatus();

								$this->template_vars['before_edit_status'] = $itemStatus;

								$item->setStatus(BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT);
								$item->set('autoclassification_manually_changed', 0)->save();

								$categoryId = InGet('id_category', 0);
								$itemCategoryToSave = null;

								if(($categoryId) && ($itemStatus == BulkClassificationRequestItem::STATUS_AUTO_CLASSIFIED_MULTY))
								{
									try
									{
										$itemCategoryToSave = BulkClassificationRequestItemCategory::getById($categoryId);
										$itemCategories = $item->getBulkClassificationRequestItemCategories();
										foreach($itemCategories as $itemCategory)
										{
											if($itemCategory->getId() !== $categoryId)
											{
												$itemCategory->remove();
											}
										}
										$this->template_vars['remove_row'] = false;
									}
									catch(Exception $ex)
									{
										break;
									}
								}
								else
								{
									CInput::set_select_data('product_category', array('' => 'Please Select'));
									CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
									CInput::set_select_data('product_item', array('' => 'Please Select'));
									$this->template_vars['remove_row'] = false;//($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'no_result');
									break;
								}

							case 'to_final' :

								if(!$itemCategoryToSave)
								{

									try
									{
										$productItem = ProductItem::getById(InGet('id_product_item'));
										if(inget('id_product_sub_category'))
										{
											$productCategory = ProductCategory::getById(InGet('id_product_category'));
											$productSubCategory = ProductCategory::getById(InGet('id_product_sub_category'));
										}
										else
										{
											$productCategory = $productItem->getCategory()->getCategory();
											$productSubCategory = $productItem->getCategory();
										}

									}
									catch(Exception $e)
									{
									}
								}
								else
								{
									$productCategory = $itemCategoryToSave->getProductCategory();
									$productSubCategory = $itemCategoryToSave->getProductSubCategory();
									$productItem = $itemCategoryToSave->getProductItem();
								}
								try
								{
									$previousProductItemId = $item->getBulkClassificationRequestItemCategories()->getFirst();
									if($previousProductItemId instanceof BulkClassificationRequestItemCategory)
									{
										$previousProductItemId->getProductItem()->getId();
									}
								}
								catch(Exception $e)
								{}
								if($productCategory && $productSubCategory && $productItem)
								{
									$item->removeCategories();
									$itemCategory = $item->setItemCategory($productCategory, $productSubCategory,
											$productItem);
								}
								else
								{
									$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
									$productCategory = $itemCategory->getProductCategory();
									$productSubCategory = $itemCategory->getProductSubCategory();
									$productItem = $itemCategory->getProductItem();
								}

								if($productItem->getId() == $item->getIdProductItem())
								{
									$item->set('autoclassification_manually_changed', 1)->save();
								}

								if($productItem->getId() != $previousProductItemId)
								{
									if ($this->getCurrentBrokerSubAccount()->isSubscribedToSkuMonitoring())
									{
										SkuMonitoringSkuChangesTrackingItem::create(
												$item->getSku()[0],
												SkuMonitoringSkuChangesTrackingItem::TYPE_OF_CHANGE_UPDATED,
												$this->getCurrentBrokerSubAccount()->getId()
										);
									}
									$item->set('autoclassification_manually_changed', 0)->save();
								}

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

								$item->set('unable_to_classify_reason', '')->save();

								$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

								if($this->template_vars['expert_account'])
								{
									$item->setClassifiedByExpert($this->user, true);
								}

								$item->set('classification_date', date('Y-m-d H:i:s'))->save();

								$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
								$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
								$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
								$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();


								//$this->template_vars['remove_row'] = ($this->template_vars['action'] != 'all' && $this->template_vars['action'] != 'validated');
								$this->template_vars['info_message_id'] = 1;
								break;
							case 'to_delete' :
								$item->setStatus(BulkClassificationRequestItem::STATUS_CANT_CLASSIFY);
								break;
							default :
								break;
						}
					}
					else
					{
						$itemCategory = $item->getBulkClassificationRequestItemCategories()->getFirst();
						$this->template_vars['product_category'] = $itemCategory->getProductCategory()->__toString();
						$this->template_vars['product_sub_category'] = $itemCategory->getProductSubCategory()->__toString();
						$this->template_vars['product_item'] = $itemCategory->getProductItem()->__toString();
					}

					$this->template_vars['current_status'] = $item->getStatus();


					if($item->getStatus() == BulkClassificationRequestItem::STATUS_AUTO_NO_RESULT)
					{
						$this->template_vars['predefined_categories_amount'] = $item->getBulkClassificationRequestItemCategoriesSortedByProductCategory()->getAmount();
						$this->template_vars['item_id_category_id'] = array();
						$this->template_vars['product_category_id'] = array();
						$this->template_vars['product_sub_category_id'] = array();
						$this->template_vars['product_item_id'] = array();

						foreach($item->getBulkClassificationRequestItemCategoriesSortedByProductCategory() as $bulkClassificationRequestItemCategory)
						{
							$this->template_vars['item_id_category_id'][] = $bulkClassificationRequestItemCategory->getId();

							try
							{
								$this->template_vars['product_category_id'][] = $bulkClassificationRequestItemCategory->getProductCategory()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_sub_category_id'][] = $bulkClassificationRequestItemCategory->getProductSubCategory()->getId();
							}

							catch(Exception $ex)
							{
								$this->template_vars['product_sub_category_id'][] = '';
							}

							try
							{
								$this->template_vars['product_item_id'][] = $bulkClassificationRequestItemCategory->getProductItem()->getId();
							}
							catch(Exception $ex)
							{
								$this->template_vars['product_item_id'][] = '';
							}
						}
					}

					if($this->template_vars['action'] !== 'all')
					{
						$itemStatus = $item->getStatus();
						if(in_array($itemStatus, BulkClassificationRequestItem::$validatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'final_class editing';
						}
						elseif(in_array($itemStatus, BulkClassificationRequestItem::$notValidatedCmsStatuses))
						{
							$this->template_vars['row_class'] = 'autm_class editing';
						}
						elseif($itemStatus == BulkClassificationRequestItem::STATUS_CANT_CLASSIFY)
						{
							$this->template_vars['row_class'] = 'unable cant_class editing';
						}
						else
						{
							$this->template_vars['row_class'] = 'editing';
						}
					}
					else
					{
						$this->template_vars['row_class'] = 'editing';
					}
				}
				catch(Exception $ex)
				{
				}
			}
			else
			{
				if(isset($_GET['BulkClassificationRequestItemsGroup_p'])
						|| isset($_GET['BulkClassificationRequestItemsGroup_s'])
				)
				{
					$this->template_vars['ajax_action'] = 'navigator';
				}
			}
		}

		if(!$this->isAjaxRequest || $this->template_vars['ajax_action'] == 'navigator')
		{
			$cantClassifyItems = $validatedItems = $notValidatedItems = $allItems = false;
			ini_set('memory_limit', '1024M');

			$this->template_vars['status_all_url'] = Url::getDirectURLBySystem('catalog_management_system');
			$this->template_vars['status_all_class'] = ($filter == '') ? 'active' : '';

			$this->template_vars['id_subaccount'] = $this->subAccountId;

			$this->template_vars['status_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix .'validated/';
			$this->template_vars['status_validated_class'] = ($filter == 'validated') ? 'active' : '';
			//$this->template_vars['status_validated_count'] = $validatedItems->getAmount();

			$this->template_vars['status_not_validated_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix. 'not_validated/';
			$this->template_vars['status_not_validated_class'] = ($filter == 'not_validated') ? 'active' : '';
			$this->template_vars['low_certainty_class'] = ($filter != 'not_validated') ? 'hidden' : '';
			//$this->template_vars['status_not_validated_count'] = $notValidatedItems->getAmount();

			$this->template_vars['status_cant_classify_url'] = Url::getDirectURLBySystem('catalog_management_system') . $statusUrlPrefix . 'cant_classify/';
			$this->template_vars['status_cant_classify_class'] = ($filter == 'cant_classify') ? 'active' : '';
			//$this->template_vars['status_cant_classify_count'] = $cantClassifyItems->getAmount();

			$this->template_vars['similar_items_exist'] = false;
			$this->template_vars['expert_service_type_message'] = '';

			switch($this->page_params[0])
			{
				case 'validated' :
					$validatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$validatedCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $validatedItems->getAmount();
					break;
				case 'not_validated' :
					$notValidatedItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$notValidatedCmsStatuses, $this->ordersOnlyFilter, $this->lowCertaintyFilter,
							$sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $notValidatedItems->getAmount();
					break;
				case 'cant_classify' :
					$cantClassifyItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							BulkClassificationRequestItem::$cantClassifyCmsStatuses, $this->ordersOnlyFilter, false, $sku,
							$this->haveOnlyCalcsFilter);
					$allItemsCount = $cantClassifyItems->getAmount();
					break;
				case 'all':
					$allItems = $filteredByCategory;
					$allItemsCount = $filteredByCategory->getAmount();
					break;
				default :
					$allItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($this->subAccountId,
							$itemInstance->allCmsStatuses, $this->ordersOnlyFilter, false, $sku, $this->haveOnlyCalcsFilter);
					$allItemsCount = $allItems->getAmount();
					break;
			}

			$this->template_vars['all_items_count'] = $allItemsCount;

			$filter = arr_val($this->page_params, 0, '');

			switch($filter)
			{
				case 'cant_classify' :
					$items = $cantClassifyItems;
					break;

				case 'validated' :
					$items = $validatedItems;
					break;

				case 'not_validated' :
					$items = $notValidatedItems;
					break;
				case 'all':
					$items = $allItems;
					break;
				default :
					$items = $allItems;
					break;
			}

			$allJobs = CMSAutoClassificationsJobsGroup::getAllJobsByStatuses(CMSAutoClassificationsJob::STATUS_WAITING);

			$timer = self::CRON_TIME_INTERVAL + ($items->getAmount() * self::TIME_FOR_ONE_ITEM_REQUEST);

			/** @var CMSAutoClassificationsJob $job */
			foreach($allJobs as $job)
			{
				$itemsCount = $job->getItemsCount();

				$timer += $itemsCount * self::TIME_FOR_ONE_ITEM_REQUEST;
			}

			$this->template_vars['timer'] = $this->downCounter($timer);

			$this->template_vars['current_url'] = $this->template_vars['HTTP'] . $this->template_vars['current_page_url'] . implode('/',
							$this->page_params) . '/';

			CInput::set_select_data('default_product_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('default_product_item', array('' => 'Please Select'));
			CInput::set_select_data('classify_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('classify_item', array('' => 'Please Select'));
			CInput::set_select_data('product_category', array('' => 'Please Select'));
			CInput::set_select_data('product_sub_category', array('' => 'Please Select'));
			CInput::set_select_data('product_item', array('' => 'Please Select'));

			$amountOfRecordsOnPage = (int)InPostGetCache('items_per_page', 100);

			SetCacheVar('items_per_page', $amountOfRecordsOnPage);

			$amountOfRecordsOnPage = $amountOfRecordsOnPage ? $amountOfRecordsOnPage : 100;

			$currentPage = InGet('items_per_page', false) ? 0 : InGetPostCache(get_class($items) . '_p', 0);

			SetCacheVar(get_class($items) . '_p', $currentPage);

			unset($_GET['items_per_page']);

			$pager = new BulkBrowserPager($items->getAmount(), 0, $amountOfRecordsOnPage);

			if($currentPage > 0 && $currentPage < $pager->getPagesAmount())
			{
				$pager->setCurrentPage($currentPage);
			}

			$searchBox = '
                <span class="catalog-all-items-count">' . $allItemsCount . ' items</span>
				<span style="" class="bulk_sku_search_block">
					<input type="text" value="' . InPostGet("sku_keyword", "Enter SKU") . '" class="txt-sm sku_keyword"/>
					<input type="submit" class="button" value="Go" />
				</span>
			';

			$navigator = '
				<span style="margin-left: 15px; display:none;" class="bulk_items_per_page_block">
					Products per page
					<input type="text" class="items_count" value = "' . $amountOfRecordsOnPage . '" />
					<input type="submit" class="button" value="Go" />
				</span>
			';

			set_time_limit(600);

			$pager->setHref(CUtils::appendGetVariable(get_class($items) . '_p=%s'));

			$itemsBrowser = $items->browse()
					->setDefaultSortField('product_title', true)
					->addColumn('Date', 'classification_date', true)
					->setCSSClass('cms-first')
					->setAlign('left')
					->setWidth('62')
					->addColumn('Calcs', 'api_calculations_count', true)
					->setAlign('left')
					->setWidth('45')
					->addColumn('SKU', 'sku', true)
					->setAlign('left')
					->setWidth('50')
					->addColumn('Description', 'product_title', true)
					->setAlign('left')
					->setWidth('178');

			$itemsBrowser->addColumn('Duty Category', 'duty_category', true)
					->setAlign('left')
					->setCSSClass('cms-category')
					->setWidth('190');

			$this->template_vars['items_browser'] =
					$itemsBrowser->addColumn($searchBox)
							->setAlign('right')
							->setWidth('300')
							->setCSSClass('last')
							->addColumn($navigator)
							->setWidth('0')
							->setCSSClass('hidden')
							->setCustomParam('filter', $filter)
							->setAmountOfRecordsOnPage($amountOfRecordsOnPage)
							->setPager($pager)
							->loadTemplate('front_catalog_management_system.tpl.php');
		}
	}

	private function approveSelectedItems($itemIds)
	{
		$items = json_decode(InPost('selected_item_ids'));
		foreach($items as $item)
		{
			try
			{
				$itemObject = BulkClassificationRequestItem::getById($item->itemID);

				$category = ProductCategory::getById($item->categoryID);
				$subCategory = ProductCategory::getById($item->subCategoryID);
				$productItem = ProductItem::getById($item->productItemID);

				$itemObject->setItemCategory($category, $subCategory, $productItem);
				$this->approveItem($itemObject);
			}
			catch(Exception $e)
			{
			}
		}
		die;
	}
	/**
	 * @param BulkClassificationRequestItem $item
	 * @throws Exception
	 */
	protected function approveItem($item)
	{
		$productItem = $item->getBulkClassificationRequestItemCategories()->getFirst()->getProductItem();
		$autoClassificationChanged = ($productItem->getId() == $item->getIdProductItem()) ? 1 : 0;

		$bulkClassification = $item->getBulkClassificationRequest();
		if(BulkClassificationExpertRequest::getByBulkClassificationRequest($bulkClassification)
				&& User::getCurrent()->getBrokerSubAccountsMasterUser()->isExpert()
		)
		{
			$item->setClassifiedByExpert($this->getUser(), true);
		}

		$item->set('unable_to_classify_reason', '')
				->set('classification_date', date('Y-m-d H:i:s'))
				->set('autoclassification_manually_changed', $autoClassificationChanged);

		$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);
		$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
	}

	private function classifySelectedItems($itemIds)
	{
		$itemIds = json_decode($itemIds);
		try
		{
			$category = ProductCategory::getById(InPost('category_id'));
			$subCategory = ProductCategory::getById(InPost('sub_category_id'));
			$productItem = ProductItem::getById(InPost('product_item_id'));
		}
		catch(Exception $e)
		{
			die;
		}

		foreach($itemIds as $itemId)
		{
			$item = BulkClassificationRequestItem::getById($itemId);
			$item->removeCategories();
			$item->setItemCategory($category, $subCategory, $productItem);

			if($productItem->getId() == $item->getIdProductItem())
			{
				$item->set('autoclassification_manually_changed', 1)->save();
			}
			else
			{
				$item->set('autoclassification_manually_changed', 0)->save();
			}

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);

			$item->set('unable_to_classify_reason', '')->save();

			$item->setAutoApproveStatus(BulkClassificationRequestItem::AUTO_APPROVE_STATUS_NO);

			if($this->template_vars['expert_account'])
			{
				$item->setClassifiedByExpert($this->user, true);
			}

			$item->set('classification_date', date('Y-m-d H:i:s'))->save();

			$item->setStatus(BulkClassificationRequestItem::STATUS_CLASSIFIED);
		}

		die;
	}

	public function on_catalog_refresh_auto_classification_submit()
	{
		$idSubaccount = inPost('id_subaccount');

		$aStatusesForAutoclassification = array_merge(BulkClassificationRequestItem::$notValidatedCmsStatuses, BulkClassificationRequestItem::$cantClassifyCmsStatuses);

		$cmsAutoclassificationItems = BulkClassificationRequestItemsGroup::getItemsByStatuses($idSubaccount, $aStatusesForAutoclassification);

		$job = CMSAutoClassificationsJob::create($idSubaccount, $cmsAutoclassificationItems->getAmount(), CMSAutoClassificationsJob::STATUS_WAITING);

		db::$calculator->internalQuery('START TRANSACTION;');
		$iC = 0;
		foreach($cmsAutoclassificationItems as $item)
		{
			$iC ++;
			/** @var $item BulkClassificationRequestItem */
			CMSAutoClassificationsItem::create($job->getId(), $item->getId(), CMSAutoClassificationsItem::STATUS_NEW);
			if ($iC % 42 == 0)
			{
				DataObject::removeAllCache();
				usleep(500);
			}
		}
		db::$calculator->internalQuery('COMMIT;');

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));
	}

	/**
	 * create timer for cms refresh auto-classification
	 *
	 * @param integer $timer
	 * @return bool|string
	 */

	private function downCounter($timer)
	{
		$checkTime = $timer;

		if($checkTime <= 0)
		{
			return false;
		}

		$days = floor($checkTime / 86400);
		$hours = floor(($checkTime % 86400) / 3600);
		$minutes = floor(($checkTime % 3600) / 60);
		$seconds = $checkTime % 60;

		$str = '';

		if($days > 0)
		{
			$str .= $this->declension($days, array('day', 'day', 'days')) . ' ';
		}
		if($hours > 0)
		{
			$str .= $this->declension($hours, array('hour', 'hour', 'hours')) . ' ';
		}
		if($minutes > 0)
		{
			$str .= $this->declension($minutes, array('minute', 'minute', 'minutes')) . ' ';
		}
		if($seconds > 0)
		{
			$str .= $this->declension($seconds, array('second', 'seconds', 'seconds'));
		}

		return $str;
	}

	/**
	 * return declension with right end for downCounter
	 *
	 * @param integer $digit
	 * @param array $expr
	 * @param bool|false $onlyWord
	 * @return string
	 */
	private function declension($digit, $expr, $onlyWord = false)
	{
		if(!is_array($expr))
		{
			$expr = array_filter(explode(' ', $expr));
		}

		if(empty($expr[2]))
		{
			$expr[2] = $expr[1];
		}

		$i = preg_replace('/[^0-9]+/s', '', $digit) % 100;

		if($onlyWord)
		{
			$digit = '';
		}
		if($i >= 5 && $i <= 20)
		{
			$res = $digit . ' ' . $expr[2];
		}
		else
		{
			$i %= 10;
			if($i == 1)
			{
				$res = $digit . ' ' . $expr[0];
			}
			elseif($i >= 2 && $i <= 4)
			{
				$res = $digit . ' ' . $expr[1];
			}
			else
			{
				$res = $digit . ' ' . $expr[2];
			}
		}

		return trim($res);
	}

	/**
	 * @param $template
	 */
	public function setPageTemplate($template)
	{
		$this->h_content = PAGES_TPL . $template;
	}

	/**
	 * Form for add items
	 */
	public function actionAddToCatalog()
	{
		try
		{
			if(InCache('fileHash', false, self::CACHE_ID) === false)
			{
				throw new Exception;
			}
			$fileHash = InCache('fileHash', false, self::CACHE_ID);
			$document = CMSUploadFileDocument::getByHash($fileHash);
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'addToCatalog',
				'fileName' => $document->getName(),
				'countItems' => InCache('countItems', '', self::CACHE_ID),
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Insert BulkClassificationRequestItem
	 */
	public function actionAddToCatalogOnSubmit()
	{
		$fileHash = InCache('fileHash', '', self::CACHE_ID);
		UnSetCacheVar('fileHash', self::CACHE_ID);

		$this->template_vars['error'] = false;

		try
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$documentCsv = $document->convertToCsv();
			$document->remove();

			CMSUploadFileJob::create($documentCsv, InCache('sub_account_id', null));

			$time = CMSUploadFileJobsGroup::getWaitingTimeInSecond();
			$this->template_vars['waiting_time'] = intval($time / 60);
		}
		catch(Exception $e)
		{
			$this->template_vars['error'] = true;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_success.tpl');

		echo parent::draw_content();
	}

	/**
	 * Delete file in cache and redirect to page upload
	 */
	public function actionDeleteFile()
	{
		if($fileHash = InCache('fileHash', false, self::CACHE_ID))
		{
			$document = CMSUploadFileDocument::getByHash($fileHash);
			$document->remove();
			UnSetCacheVar('fileHash', self::CACHE_ID);
		}

		$this->actionUploadFile();
	}

	/**
	 * Form for upload file, if file already uploaded redirect to add item page
	 */
	public function actionUploadFile()
	{
		if(InCache('fileHash', false, self::CACHE_ID) !== false)
		{
			$this->actionAddToCatalog();

			return;
		}

		$this->setPageTemplate('front_catalog_management_system_upload_file.tpl');
		$this->template_vars = array_merge($this->template_vars, array(
				'action' => 'uploadFile',
				'apiKey' => $this->getApiKeyName(),
				'documentUrl' => getUrl() . 'document_templates/catalog_management_system'
		));

		echo parent::draw_content();
	}

	/**
	 * Validate upload file
	 */
	public function actionUploadFileOnSubmit()
	{
		$file = $_FILES['fileToUpload'];

		try
		{
			$countItems = CMSUploadFileDocument::getNumberOfLines($file);
			$document = CMSUploadFileDocument::create($file['name']);
			move_uploaded_file($file['tmp_name'], $document->getPath());
		}
		catch(ValidatorException $e)
		{
			$this->template_vars['error'] = $e->getMessage();
			$this->actionUploadFile();

			return;
		}
		catch(Exception $e)
		{
			$this->actionUploadFile();

			return;
		}

		SetCacheVar('', '', self::CACHE_ID);
		SetCacheVar('', array(
				'fileHash' => $document->getHash(),
				'countItems' => $countItems
		), self::CACHE_ID);
		$this->actionAddToCatalog();
	}

	/**
	 * Get api key name for current user
	 * @return mixed
	 */
	public function getApiKeyName()
	{
		return BrokerSubAccount::getById(InCache('sub_account_id'))->getAccountName();
	}

	public function output_page()
	{
		$form = InPostGet('f_in', false);
		$action = $form ? $form . 'onSubmit' : $this->getAction();

		$actionPrefix = ($this->ajaxRequest) ? 'ajax' : 'action';
		$action = $actionPrefix . $action;

		if(method_exists($this, $action))
		{
			$this->$action();

			return;
		}
		$this->categoriesTreeControl->setBrokerSubAccount($this->getCurrentBrokerSubAccount());
		parent::output_page();
	}

	function on_catalog_for_form_submit()
	{

		SetCacheVar('sub_account_id', inPost('account_or_website'));

		if(InCache('sub_account_id'))
		{
			$this->subAccountId = InCache('sub_account_id');
		}

		$this->internalRedirect(Url::getDirectURLBySystem('catalog_management_system'));

	}
}