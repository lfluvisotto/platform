<?php

namespace Oro\Bundle\ImportExportBundle\Tests\Unit\Form\Model;

use Oro\Bundle\ImportExportBundle\Form\Model\ExportTemplateData;
use Symfony\Component\PropertyAccess\PropertyAccess;

class ExportTemplateDataTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider propertiesDataProvider
     * @param string $property
     * @param mixed $value
     */
    public function testSettersAndGetters($property, $value)
    {
        $obj = new ExportTemplateData();

        $accessor = PropertyAccess::createPropertyAccessor();
        $accessor->setValue($obj, $property, $value);
        $this->assertEquals($value, $accessor->getValue($obj, $property));
    }

    public function propertiesDataProvider()
    {
        return array(
            array('processorAlias', 'test')
        );
    }
}