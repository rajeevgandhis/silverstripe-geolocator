<?php
/**
 * Provides actions for querying a database of geographical coordinates.
 * @author simonwade
 */
class GeoLocationController extends Controller {

	static $url_handlers = array(
		'search' => 'search',
		'nearest/$Postcode' => 'nearest',
	);

	/**
	 * Provides the configuration for queries.
	 * @var GeoLocator
	 */
	protected $geoLocator;

	/**
	 * Defines the way that results should be formatted. Supported formats are: xml
	 * @var string
	 */
	protected $outputFormat = 'xml';

	public function nearest( SS_HTTPRequest $request ) {
		$locator = $this->getGeoLocator();
		if( $locations = GeoLocation::getByKeyword(Convert::raw2sql($request->param('Postcode'))) ) {
			$this->GeoLocation = $locations->pop();
			$results = $locator->getResultsByDistance($this->GeoLocation, $request->requestVar('Radius'));
		}
		if( !$results ) {
			$results = new DataObjectSet();
		}
		if( !$this->response ) {
			$this->response = new SS_HTTPResponse();
		}
		$response = $this->getResultsMarkup($results);
		$this->response->setBody($response);
		return $this->response;
	}

	public function getResultsMarkup( $results ) {
		$locator = $this->getGeoLocator();
		switch( $this->outputFormat ) {
		case 'xml':
			$this->response->addHeader('Content-type', 'text/xml');
			$response = "<?xml version=\"1.0\"?>\n<markers>\n";
			foreach( $results as $result ) { /* @var $result DataObject */
				$response .= "\t<marker";
				foreach( $locator->getMarkerAttributes($result) as $name => $value ) {
					$response .= " $name=\"".Convert::raw2xml($value)."\"";
				}
				$response .= "/>\n";
			}
			$response .= '</markers>';
			return $response;
			break;
		default:
			throw new Exception("Unsupported output format '$this->outputFormat'");
		}
	}

	public function getGeoLocator() {
		if( !$this->geoLocator ) {
			$this->geoLocator = new GeoLocator();
		}
		return $this->geoLocator;
	}

	public function setGeoLocator( $locator ) {
		$this->geoLocator = $locator;
	}

	/**
	 * Returns a json encoded list of GeoLocation's that match keyword.
	 * @param string|SS_HTTPRequest $request
	 * @return string
	 * @author Alex Hayes <alex.hayes@dimension27.com>
	 */
	public function search( $request ) {
		if( $keyword = is_object($request) ? $request->requestVar('term') : $request ) {
			$geoLocations = GeoLocation::getByKeyword($keyword, 10);
			$response = array();
			foreach( $geoLocations->map('ID', 'getFullTitle') as $id => $label ) {
				$response[] = array(
					'id' => $id,
					'label' => $label
				);
			}
		}
		else {
			$response = false;
		}
		return json_encode($response);
	}
	
}

class GeoLocator {

	protected $defaultRadius = 20;
	protected $dataObject = 'GeoLocation';
	protected $latitudeField = 'GeoLocation.Latitude';
	protected $longitudeField = 'GeoLocation.Longitude';

	public function searchFor( DataObject $dataObject, $latitudeField = null, $longitudeField = null ) {
		$this->dataObject = $dataObject;
		if( $latitudeField ) {
			$this->latitudeField = $latitudeField;
		}
		if( $longitudeField ) {
			$this->longitudeField = $longitudeField;
		}
	}

	public function getResultsByDistance( $geoLocation, $radius = null ) {
		$formula = $geoLocation->getDistanceFormula($this->latitudeField, $this->longitudeField);
		if( !$radius ) {
			$radius = $this->defaultRadius;
		}
		$sql = new SQLQuery();
		$sql->select("$this->dataObject.*, $formula AS Distance")
			->from($this->dataObject)
			->having('Distance < '.$radius)
			->groupby("$this->dataObject.ID")
			->orderby('Distance ASC');
		return singleton($this->dataObject)->buildDataObjectSet($sql->execute());
	}

	public function getMarkerAttributes( $result ) {
		return array(
			'name' => $result->Title,
			'lat' => $result->Latitude,
			'lng' => $result->Longitude,
		);
	}

}

class GeolocatableLocator extends GeoLocator {

	public function __construct( $dataObject ) {
		$this->searchFor($dataObject, "$dataObject.Lat", "$dataObject.Lng");
	}

	public function getMarkerAttributes( $result ) {
		return array(
			'name' => $result->Title,
			'address' => $result->getFullAddress(),
			'lat' => $result->Lat,
			'lng' => $result->Lng,
		);
	}

}
