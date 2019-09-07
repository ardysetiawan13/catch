<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpKernel\KernelInterface;

use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use \stdClass;
use \SimpleXMLElement;
use \DOMDocument;
use \CurlFile;

class MainController extends Controller{
	//const BASE_URL = "http://localhost/belajar/symfony/catch/";
	const BASE_URL = "http://catch.ardysetiawan.id/";
	private $rootDir;

	public function __construct(KernelInterface $appKernel){
        $this->rootDir = $appKernel->getProjectDir();
    }

	private function getInputWithSdk(){

		// https://s3-ap-southeast-2.amazonaws.com/catch-code-challenge/challenge-1-in.jsonl

		$s3 = new S3Client([
		    'version' => 'latest',
		    'region'  => 'ap-southeast-2',
		    'http'    => [
		        'connect_timeout' => 0,
		        'timeout' => 0
		    ]
		]);

		try {
		    // Get the object.
		    $result = $s3->getObject([
		        'Bucket' => "catch-code-challenge",
		        'Key'    => "challenge-1-in.jsonl"
		    ]);

		    // Display the object in the browser.
		    header("Content-Type: {$result['ContentType']}");
		    return new Response($result['Body']);;
		} catch (S3Exception $e) {
		    return new Response($e->getMessage() . PHP_EOL); ;
		}
	}

	private function getInput(){

		$file = fopen ($this->rootDir.'/assets/myfile.jsonl', 'w+');
		
		$curl_handle = curl_init();
		curl_setopt($curl_handle, CURLOPT_URL, "https://s3-ap-southeast-2.amazonaws.com/catch-code-challenge/challenge-1-in.jsonl");
		curl_setopt($curl_handle, CURLOPT_FILE, $file); 
		$st = curl_exec($curl_handle);
		
		fclose($file);
		curl_close($curl_handle);

		return $st;
	}

	private function compareInt($a, $b){
		if($a < $b){
			return -1;
		}
		if($a > $b){
			return 1;
		}
		else{
			return 0;
		}
	}

	private function discount($total, $discount, $type){
		if($type == "DOLLAR"){
			return $discount;
		}
		else if($type == "PERCENTAGE"){
			return $total *  $discount / 100;
		}
		else{
			return 0;	
		} 
	}

	private function array_to_xml($data, &$xml_data) {        
	    foreach( $data as $rows ) {
           $subnode = $xml_data->addChild("Order");
           foreach ($rows as $key => $value) {
           		$subnode->addChild($key, $value);
           }
        }      
	}

	private function readData(){
		
		$file = fopen($this->rootDir.'/assets/myfile.jsonl','r');
		
		$newArray = array();
		while (!feof($file)){
		    $line = fgets($file);
		    $obj = json_decode($line);
		    
		    if($obj){
		    	array_push($newArray, $obj);
		    }
		}
		

		$results = array();
		foreach ( $newArray as $row ) {

			$totalOrder = 0;
			$totalQty = 0;
			$items = array();
			foreach ($row->items as $item) {
				$subTotal = (float) $item->unit_price * (float) $item->quantity;
				$totalOrder += round($subTotal);
				$totalQty += (float) $item->quantity;

				array_push($items, $item->product->product_id);
			}
			

			$discounts = $row->discounts;
			usort( $discounts, array($this, "compareInt") );

			$totalDiscount = 0;
			$totalOrderAfterDiscount = $totalOrder;
			foreach ($discounts as  $discount) {
				$currentDiscount = $this->discount($totalOrderAfterDiscount, $discount->value, $discount->type);
				$totalDiscount += $currentDiscount;
				$totalOrderAfterDiscount = $totalOrderAfterDiscount - $currentDiscount;
			}

			$dateOrder = date_create_from_format("D, d M Y H:i:s O", $row->order_date);
			
			$order = new stdClass();
			$order->id 					= $row->order_id;
			$order->date 				= $dateOrder->format("Y-m-d\TH:i:s\Z");
			$order->customer_id 		= $row->customer->customer_id;
			$order->state 				= $row->customer->shipping_address->state;
			$order->total 				= $totalOrder;
			$order->totalDiscount 		= $totalDiscount;
			$order->totalAfterDiscount 	= $totalOrderAfterDiscount;
			$order->totalQty 			= $totalQty;
			$order->avgPrice 			= round($totalOrder/$totalQty, 2);
			$order->uniqueItem 			= count( array_unique($items) );
			
			if( $order->total > 0 ){
				array_push($results, $order);
			}

		}

		return $results;
	}

	private function saveCsv($data){
		$file = fopen($this->rootDir.'/assets/out.csv', 'w');
		$header = array("id","date","customer_id","state","total","totalDiscount","totalAfterDiscount","totalQty","avgPrice","uniqueItem");
		
		fputcsv($file, $header);
		foreach ($data as $row) {
		    fputcsv($file, get_object_vars($row));
		}
		fclose($file);
	}

	private function csvValidation(){
		$result = shell_exec('curl -L --data "urls[]='.self::BASE_URL.'public/download?format=csvstream" http://csvlint.io/package.json');

		return $result;
	}

	private function saveXml($data){
		$xml = new SimpleXMLElement('<Orders/>');
		$this->array_to_xml($data, $xml);

		// for save xml as file
		// $xmlFile = new DOMDocument('1.0');
		// $xmlFile->preserveWhiteSpace = false;
		// $xmlFile->formatOutput = true;
		// $xmlFile->loadXML($xml->asXML());
		// $xmlFile->save($this->rootDir."/assets/out.xml");
		
		return $xml;
	}

	/**
	 * @Route("/test", name="index")
	 * @Method({"GET"})
	 */
	public function index(){
		return $this->render("catch.html.twig");
	}

	/**
	 * @Route("/data", name="data")
	 * @Method({"GET"})
	 */
	public function dataOrder(){
		// $getInputStatus = $this->getInputWithSdk();
		$getInputStatus = $this->getInput();
		if( !$getInputStatus ){
			return new Response( "failed to get data" );
		}

		$result = $this->readData();
		// $this->saveCsv($result);

		return $this->json($result);
	}

	/**
	 * @Route("/download", name="download")
	 * @Method({"GET"})
	 */
	public function download(Request $req){

		$result = $this->readData();
		switch ( $req->query->get("format") ) {
			case 'csv':
				$this->saveCsv($result);
				$response = new BinaryFileResponse($this->rootDir.'/assets/out.csv');
				$response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT);
				return $response;
				break;

			case 'csvstream':
				$response = new BinaryFileResponse($this->rootDir.'/assets/out.csv');
				$response->headers->set('Content-Type', 'text/csv; charset=utf-8');				
				return $response;
				break;

			case 'xml':
				$data = $this->saveXml($result);
				$response = new Response ($data->asXML());
				$response->headers->set('Content-Type', 'text/xml');
				return $response;
				break;
			
			case "csvvalidation":
				$validation = json_decode( $this->csvValidation() );
				return $this->json($validation);
				break;

			default:
				return $this->json($result);
				break;
		}


	}

	
}

?>