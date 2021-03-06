<?php
App::uses('AllTestsBase', 'Test/Lib');

class AllCronsTestsTest extends AllTestsBase {

/**
 * Suite define the tests for this suite
 *
 * @return void
 */
	public static function suite() {
		$suite = new CakeTestSuite('All Crons test');

		$path = CakePlugin::path('Crons') . 'Test' . DS . 'Case' . DS;
		$suite->addTestDirectoryRecursive($path);

		return $suite;
	}
}
