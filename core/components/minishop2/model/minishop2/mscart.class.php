<?php

interface msCartInterface {

	/* Adds product to cart
	 *
	 * @param integer $id Id of MODX resource. It must be an msProduct descendant
	 * @param integer $count.A number of product exemplars
	 * @param array $data Additional data of the product: color, type etc.
	 *
	 * @return array|string $response
	 * */
	public function add($id, $count = 1, $data = array());

	/* Removes product from cart
	 *
	 * @param string $key The unique key of cart item
	 *
	 * @return array|string $response
	 * */
	public function remove($key);

	/* Changes products count in cart
	 *
	 * @param string $key The unique key of cart item
	 * @param integer $count.A number of product exemplars
	 *
	 * @return array|string $response
	 * */
	public function change($key, $count);

	/* Cleans the cart
	 *
	 * @return array|string $response
	 * */
	public function clean();

	/* Returns the cart status: number of items, weight, price.
	 *
	 * @param array $data Additional data to return with status
	 * @return array $status
	 * */
	public function status();

	/* Returns the cart items
	 *
	 * @return array $cart
	 * */
	public function get();

	/* Returns the cart items
	 *
	 * @return void
	 * */
	public function set($cart = array());

}


class msCart implements msCartInterface {
	private $cart;


	function __construct(modX &$modx, array $config = array()) {
		$this->modx =& $modx;

		$this->config = array_merge(array(
			'cart' => & $_SESSION['minishop2']['cart']
			,'json_response' => false
			,'max_count' => 1000
			,'allow_deleted' => false
			,'allow_unpublished' => false
		),$config);

		$this->cart = & $this->config['cart'];
		$this->modx->lexicon->load('minishop2:cart');

		if (empty($this->cart) || !is_array($this->cart)) {
			$this->cart = array();
		}
	}


	/* @inheritdoc} */
	public function add($id, $count = 1, $data = array()) {
		if (empty($id) || !is_numeric($id)) {
			return $this->error('ms2_cart_add_err_id');
		}
		$count = intval($count);

		$filter = array('id' => $id);
		if (!$this->config['allow_deleted']) {$filter['deleted'] = 0;}
		if (!$this->config['allow_unpublished']) {$filter['published'] = 1;}
		/* @var msProduct $product */
		if ($product = $this->modx->getObject('modResource', $filter)) {
			if (!($product instanceof msProduct)) {
				return $this->error('ms2_cart_add_err_product', $this->status());
			}
			if ($count > $this->config['max_count']) {
				return $this->error('ms2_cart_add_err_count', $this->status(), array('count' => $count));
			}

			$this->modx->invokeEvent('msOnBeforeAddToCart', array('product' => & $product, 'count' => & $count, 'data' => & $data, 'cart' => $this));

			$key = md5($id.(json_encode($data)));
			if (array_key_exists($key, $this->cart)) {
				return $this->change($key, $this->cart[$key]['count'] + $count);
			}
			else {
				$this->cart[$key] = array(
					'id' => $id
					,'price' => $product->getPrice()
					,'weight' => $product->getWeight()
					,'count' => $count
					,'data' => $data
				);
				$this->modx->invokeEvent('msOnAddToCart', array('key' => $key, 'cart' => $this));
				return $this->success('ms2_cart_add_success', $this->status(array('key' => $key)));
			}
		}

		return $this->error('ms2_cart_add_err_nf', $this->status());
	}


	/* @inheritdoc} */
	public function remove($key) {
		if (array_key_exists($key, $this->cart)) {
			$this->modx->invokeEvent('msOnBeforeRemoveFromCart', array('key' => $key, 'cart' => $this));
			unset($this->cart[$key]);
			$this->modx->invokeEvent('msOnRemoveFromCart', array('key' => $key, 'cart' => $this));

			return $this->success('ms2_cart_remove_success', $this->status());
		}
		else {
			return $this->error('ms2_cart_remove_error');
		}
	}


	/* @inheritdoc} */
	public function change($key, $count) {
		if (array_key_exists($key, $this->cart)) {
			if ($count <= 0) {
				return $this->remove($key);
			}
			else {
				$this->modx->invokeEvent('msOnBeforeChangeInCart', array('key' => $key, 'count' => $count, 'cart' => $this));
				$this->cart[$key]['count'] = $count;
				$this->modx->invokeEvent('msOnChangeInCart', array('key' => $key, 'count' => $count, 'cart' => $this));
			}
			return $this->success('ms2_cart_change_success', $this->status(array('key' => $key)));
		}
		else {
			return $this->error('ms2_cart_change_error', $this->status());
		}
	}


	/* @inheritdoc} */
	public function clean() {
		$this->modx->invokeEvent('msOnBeforeEmptyCart', array('cart' => $this));
		$this->cart = array();
		$this->modx->invokeEvent('msOnEmptyCart', array('cart' => $this));

		return $this->success('ms2_cart_clean_success', $this->status());
	}


	/* @inheritdoc} */
	public function status($data = array()) {
		$status = array(
			'total' => 0
			,'count' => 0
			,'weight' => 0
		);
		foreach ($this->cart as $item) {
			$status['count'] += $item['count'];
			$status['total'] += $item['price'] * $item['count'];
			$status['weight'] += $item['weight'] * $item['count'];
		}
		return array_merge($data, $status);
	}


	/* @inheritdoc} */
	public function get() {
		return $this->cart;
	}


	/* @inheritdoc} */
	public function set($cart = array()) {
		$this->cart = $cart;
	}


	/* This method returns an error of the cart
	 *
	 * @param string $message A lexicon key for error message
	 * @param array $data.Additional data, for example cart status
	 * @param array $placeholders Array with placeholders for lexicon entry
	 *
	 * @return array|string $response
	 * */
	public function error($message = '', $data = array(), $placeholders = array()) {
		$response = array(
			'success' => false
			,'message' => $this->modx->lexicon($message, $placeholders)
			,'data' => $data
		);
		if ($this->config['json_response']) {
			return json_encode($response);
		}
		else {
			return $response;
		}
	}


	/* This method returns an success of the cart
	 *
	 * @param string $message A lexicon key for success message
	 * @param array $data.Additional data, for example cart status
	 * @param array $placeholders Array with placeholders for lexicon entry
	 *
	 * @return array|string $response
	 * */
	public function success($message = '', $data = array(), $placeholders = array()) {
		$response = array(
			'success' => true
			,'message' => $this->modx->lexicon($message, $placeholders)
			,'data' => $data
		);
		if ($this->config['json_response']) {
			return json_encode($response);
		}
		else {
			return $response;
		}
	}

}