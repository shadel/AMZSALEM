<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Product extends CI_Controller {

	/**
	 * @author NNTrung
	 * @name __construct
	 * @todo
	 * @param
	 */
	public function __construct()
	{
		parent::__construct();
		$this->load->library('parser');
		check_login(false);
	}

	public function index()
	{
		$productModel = new ProductModel();
		$product_list = $productModel->getList();
		
		$data = array();
		$data['product_list'] = $product_list;
		$this->layout->view('product_list', $data);
	}
	
	public function categories()
	{
		$data = array();
		$this->layout->view('product_categories', $data);
	}
	
	public function refresh_product_status()
	{
		$productModel = new ProductModel();
		$product_list = $productModel->findByStatus('_SUBMITTED_');
		$id_list = array();
		foreach ($product_list as $product) {
			array_push($id_list, $product->feedSubmissionId);
		}
		if ($id_list) {
			try {
				$result = $this->amazon_api->getFeedSubmissionListById($id_list);
				if ($result['feedSubmissionInfoList']) {
					$result = $this->update_products_status($result['feedSubmissionInfoList']);
					
					echo json_encode($result);
				}
			} catch (Exception $e)
			{
				echo $e->getMessage();
			}
		}
	}
	
	private function update_products_status($product_feed_list) {
		
		$status_list = array();
		foreach ($product_feed_list as $product_feed) {
			array_push($status_list, $this->update_product_status($product_feed));
		}
		return $status_list;
	}
	
	private function update_product_status($product_feed) {
		if ($product_feed['FeedProcessingStatus'] != '_DONE_') {
			return;
		}
		
		$result = $this->amazon_api->getFeedSubmissionResult($product_feed['FeedSubmissionId']);
		$productModel = new ProductModel();
		if ($result->Message->ProcessingReport->ProcessingSummary->MessagesSuccessful) {
			$productModel->updateStatus($product_feed['FeedSubmissionId'], '_SUCCESS_');
			return array($product_feed['FeedSubmissionId'] => '_SUCCESS_');
		} else {
			$productModel->updateStatus($product_feed['FeedSubmissionId'], '_FAIL_');
			return array($product_feed['FeedSubmissionId'] => '_FAIL_');
		}
	}
	/** Edit product*/
	public function edit()
	{
		$sku = $this->input->post('SKU');
		$data = array();
		$productModel = new ProductModel();
		$product = $productModel->findBySKU($sku);
		$product->productData = new SimpleXMLElement($product->productData);
		$imageModel = new ImageModel();
		$product->imageList = $imageModel->findBySKU($sku);
		$data['categories'] = $product->categories;
		$data['product'] = $product;
		
		$this->layout->view('product_edit', $data);
	}
	
	public function image() {
		$this->load->library('uploadHandler');
	}
	
	public function update_image() {
		$imageType = array('Main', 'Swatch', 'PT1', 'PT2', 'PT3', 'PT4', 'PT5', 'PT6', 'PT7', 'PT8', 'Search');
		
		$messages = '';
		$imageList = array();
		$count = 0;
		foreach ($imageType as $type) {
			if ($this->input->post($type)) {
				$count++;
				array_push($imageList, array(
						'Id' => $count,
						'SKU' => $this->input->post('SKU'),
						'ImageType' => $type,
						'ImageLocation' => $this->input->post($type)
						));
			}
		}
		if (!$imageList) {
			return;
		}
		foreach ($imageList as $image) {
				$messages.= $this->parser->parse('xml/product_image_message', $image, TRUE);
		}
		$feed =  $this->parser->parse('xml/product_image', array(
				'MerchantIdentifier' => 'A2T7KN13JZ9T6W',
				'Message' => $messages), TRUE);
		echo $feed;
		try {
			$result = $this->amazon_api->submitImage($feed);
			
			foreach ($imageList as $image) {
				$imageModel = new ImageModel();
				$imageModel->SKU = $image['SKU'];
				$imageModel->ImageType = $image['ImageType'];
				$imageModel->ImageLocation = $image['ImageLocation'];
				$imageModel->feedSubmissionId = $result['FeedSubmissionId'];
				$imageModel->feedStatus = $result['FeedProcessingStatus'];
	
				$imageModel->save_or_update();
			}
			
		} catch (Exception $e) {
			echo $e->getMessage();
		}
	}
	
	/** Add product */
	public function add_categories($categories)
	{
		$data = array();
		$data['is_edit'] = false;
		$data['categories'] = $categories;
		$productModel = new ProductModel();
		$productModel->productData = array();
		
		if (!$this->input->post()) {
			$data['product'] = $productModel;
			$this->layout->view('product_form', $data);
		} else {
			$this->validation($data, $productModel, false);
		}
	}

	public function add()
	{
		$data = array();
		$data['is_edit'] = false;
		$data['categories'] = $this->input->post('categories');
		$productModel = new ProductModel();
			
		if (!$this->input->post()) {
			$data['product'] = $productModel;
			$this->layout->view('product_form', $data);
		} else {
			$this->validation($data, $productModel, false);
		}
	}

	private function validation($data, $productModel, $is_update) {
		$this->load->library('form_validation');

		$this->form_validation->set_rules('title', 'Product Name', 'trim|required|xss_clean');
		$this->form_validation->set_rules('itemPackageQuantity', 'Item Package Quantity', 'trim|numeric');
		$this->form_validation->set_rules('brand', 'Brand', 'trim');
		$this->form_validation->set_rules('country', 'Country', 'trim');
		$this->form_validation->set_rules('MSRP', 'MSRP', 'trim');
		$this->form_validation->set_rules('manufacturer', 'Manufacturer', 'trim');
		$this->form_validation->set_rules('description', 'Description', 'trim');

		$productModel->title = trim($this->input->post('title'));
		$productModel->itemPackageQuantity = trim($this->input->post('itemPackageQuantity'));
		$productModel->brand = trim($this->input->post('brand'));
		$productModel->country = trim($this->input->post('country'));
		$productModel->MSRP = trim($this->input->post('MSRP'));
		$productModel->manufacturer = trim($this->input->post('manufacturer'));
		$productModel->description = trim($this->input->post('description'));
		$productModel->productData = $this->input->post('productData');

		if($this->form_validation->run() == false) {
			$data['product'] = $productModel;
			$this->layout->view('product_form', $data);
		} else {
			$productModel->SKU = $this->generateSKU();
			$productModel->UPC = "4015643103921";
			$productModel->productData = $this->parser->parse('xml/product_'.$data['categories'], $productModel->productData, TRUE);
			$productModel->categories = $data['categories'];

			try{
				$data = array(
						'MerchantIdentifier' => 'A2T7KN13JZ9T6W',
						'Title' => $productModel->title,
						'SKU' => $productModel->SKU,
						'UPC' => $productModel->UPC,
						'ItemPackageQuantity' => $productModel->itemPackageQuantity,
						'Brand' => $productModel->brand,
						'Country' => $productModel->country,
						'MSRP' => $productModel->MSRP,
						'Manufacturer' => $productModel->manufacturer,
						'MfrPartNumber' => '234-12',
						'Description' => $productModel->description,
						'ProductData' => $productModel->productData
				);
				$feed = $this->parser->parse('xml/product_template', $data, TRUE);
				echo "<textarea>".$feed."</textarea>";
					
				$result=$this->amazon_api->submitFeed($feed);
				$productModel->feedSubmissionId = $result['FeedSubmissionId'];
				$productModel->feedStatus = $result['FeedProcessingStatus'];
	
				$productModel->save();
			}
			catch(Exception $e)
			{
				echo $e->getMessage();
			}
			
			redirect('product/categories');
		}
	}

	function SKU_check($sku, $is_update = false){
		if ($is_update) {
			return true;
		}

		$productModel = new ProductModel();
		$product = $productModel->findBySKU($sku);
		if ($product->result_count() == 1) {
			$product->form_validation->set_message('SKU_check', 'The %s is exist');
			return false;
		} else {
			return true;
		}
	}

	function generateRandomString($length = 17) {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$randomString = '';
		for ($i = 0; $i < $length; $i++) {
			$randomString .= $characters[rand(0, strlen($characters) - 1)];
		}
		return $randomString;
	}
	
	function generateSKU() {
		$sku = $this->generateRandomString();
		if (!$this->SKU_check($sku)) {
			$sku = $this->generateSKU();
		}
		return $sku;
	}


}