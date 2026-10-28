<?php

namespace Tests\Unit;

use Composer\InstalledVersions;
use PhpOffice\PhpSpreadsheet\Reader\Exception;
use PhpOffice\PhpSpreadsheet\Reader\Ods;
use PhpOffice\PhpSpreadsheet\Reader\Security\XmlScanner;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SpreadsheetDependencySecurityTest extends TestCase
{
    public function test_patched_dependency_and_unicode_xlsx_roundtrip_remain_usable(): void
    {
        $this->assertGreaterThanOrEqual(0, version_compare(InstalledVersions::getVersion('phpoffice/phpspreadsheet'), '5.10.0'));
        $file = tempnam(sys_get_temp_dir(), 'hn-toolchain-xlsx-');
        $this->assertIsString($file);
        try {
            $book = new Spreadsheet;
            $book->getActiveSheet()->fromArray([['SKU', 'Số lượng'], ['TEST-DCJZ2090', 1], ['Máy khoan & vặn vít', 2]]);
            (new XlsxWriter($book))->save($file);
            $read = (new Xlsx)->load($file);
            $this->assertSame('TEST-DCJZ2090', $read->getActiveSheet()->getCell('A2')->getValue());
            $this->assertSame('Máy khoan & vặn vít', $read->getActiveSheet()->getCell('A3')->getValue());
            $this->assertSame(2, $read->getActiveSheet()->getCell('B3')->getValue());
            $read->disconnectWorksheets();
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
    }

    public function test_iso2022_hidden_doctype_is_rejected_before_xml_can_be_loaded(): void
    {
        $doctype = implode("\x1B(B", str_split('<!DOCTYPE')).' root [<!ENTITY t "TEST">]>';
        $xml = '<?xml version="1.0" encoding="ISO-2022-JP"?>'.$doctype.'<root>&t;</root>';
        $this->expectException(Exception::class);
        (new XmlScanner)->scan($xml);
    }

    public function test_unbounded_ods_row_repeat_is_rejected_without_allocating_rows(): void
    {
        $guard = new ReflectionMethod(Ods::class, 'checkRowsRepeated');
        $this->expectException(Exception::class);
        $guard->invoke(null, 1, 1073741824);
    }

    public function test_bounded_ods_row_repeat_is_still_permitted(): void
    {
        $guard = new ReflectionMethod(Ods::class, 'checkRowsRepeated');
        $this->assertNull($guard->invoke(null, 1, 2));
    }
}
