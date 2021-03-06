<?php
/**
 * @filesource modules/ar/models/detail.php
 *
 * @copyright 2016 Goragod.com
 * @license http://www.kotchasan.com/license/
 *
 * @see http://www.kotchasan.com/
 */

namespace Ar\Detail;

use Gcms\Login;
use Kotchasan\Database\Sql;
use Kotchasan\Date;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * โมเดลสำหรับแสดงรายละเอียดของบัญชี (detail.php).
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * อ่านข้อมูลลูกค้า.
     *
     * @param int $id
     *
     * @return object|null คืนค่าผลลัพท์ที่พบเพียงรายการเดียว ไม่พบข้อมูลคืนค่า null
     */
    public static function get($id)
    {
        if (empty($id)) {
            // ใหม่
            return (object) array(
                'id' => 0,
            );
        } else {
            // แสดงรายละเอียด/แก้ไข
            $model = new static();
            $q1 = $model->db()->createQuery()
                ->select('X.member_id', Sql::SUM('X.amount', 'amount'))
                ->from('ar_details X')
                ->where(array(array('X.office_id', $id), array('X.type', 'out')))
                ->groupBy('X.member_id');
            $q2 = $model->db()->createQuery()
                ->select(Sql::create('GROUP_CONCAT(`member_id`,"|",`amount`)'))
                ->from(array($q1, 'Q'));
            $q3 = $model->db()->createQuery()
                ->select('office_id', Sql::MAX('percent', 'interest'), Sql::MIN('create_date', 'create_date'))
                ->from('ar_details')
                ->where(array(
                    array('office_id', $id),
                    array('type', 'out'),
                ))
                ->groupBy('office_id');

            return $model->db()->createQuery()
                ->from('ar O')
                ->join(array($q3, 'C'), 'LEFT', array('C.office_id', 'O.id'))
                ->where(array('O.id', $id))
                ->first('O.*', array($q2, 'creditor'), 'C.interest', 'C.create_date');
        }
    }

    /**
     * อ่านรายชื่อเจ้าหนี้.
     *
     * @return array
     */
    public static function getCreditors()
    {
        $model = new static();
        $query = $model->db()->createQuery()
            ->select('id', 'name')
            ->from('user')
            ->where(array('permission', 'LIKE', '%loan_payable%'))
            ->order('id')
            ->toArray()
            ->cacheOn();
        $result = array();
        foreach ($query->execute() as $item) {
            $result[$item['id']] = $item['name'];
        }

        return $result;
    }

    /**
     * บันทึกข้อมูล.
     *
     * @param Request $request
     */
    public function submit(Request $request)
    {
        $ret = array();
        // referer, session, accountant
        if ($request->initSession() && $request->isReferer() && $login = Login::isMember()) {
            if (Login::notDemoMode($login) && Login::checkPermission($login, 'accountant')) {
                try {
                    // POST
                    $save = array(
                        'name' => $request->post('name')->topic(),
                        'sex' => $request->post('sex')->filter('fm'),
                        'phone' => $request->post('phone')->topic(),
                        'id_card' => $request->post('id_card')->number(),
                        'expire_date' => $request->post('expire_date')->date(),
                        'address' => $request->post('address')->topic(),
                        'provinceID' => $request->post('provinceID')->number(),
                        'zipcode' => $request->post('zipcode')->number(),
                        'detail' => $request->post('detail')->topic(),
                        'comment' => $request->post('comment')->topic(),
                        'interest' => $request->post('interest')->toDouble(),
                        'period' => $request->post('period')->toInt(),
                        'period_type' => $request->post('period_type')->toInt(),
                        'include_interest' => $request->post('include_interest')->toInt(),
                        'aggregate' => $request->post('aggregate')->toDouble(),
                    );
                    // ตรวจสอบค่าที่ส่งมา
                    if ($save['name'] == '') {
                        $ret['ret_name'] = 'Please fill in';
                    }
                    if (empty($ret)) {
                        // ตาราง
                        $ar_table = $this->getTableName('ar');
                        $details_table = $this->getTableName('ar_details');
                        // ตรวจสอบรายการที่ต้องการ
                        $index = self::get($request->post('id')->toInt());
                        if ($index->id == 0) {
                            $save['id'] = $this->db()->getNextId($ar_table);
                        } else {
                            $save['id'] = $index->id;
                        }
                        // บันทึก
                        if ($index->id == 0) {
                            // บันทึกลูกค้า
                            $this->newCustomer($save);
                            // บันทึกบัญชี
                            $this->db()->insert($ar_table, $save);
                            // วันที่ของสัญญา
                            $create_date = strtotime($request->post('create_date')->date());
                            // เงินต้น
                            $total = 0;
                            // บันทึกเงินต้น
                            foreach ($request->post('creditor')->toDouble() as $creditor_id => $amount) {
                                if ($amount > 0) {
                                    $total += $amount;
                                    $this->db()->insert($details_table, array(
                                        'office_id' => $save['id'],
                                        'amount' => $amount,
                                        'percent' => $save['interest'],
                                        'type' => 'out',
                                        'member_id' => $creditor_id,
                                        'create_date' => $create_date,
                                    ));
                                    // ดอกเบี้ย
                                    $this->db()->insert($details_table, array(
                                        'office_id' => $save['id'],
                                        'amount' => $amount * $save['interest'] / 100,
                                        'type' => 'in',
                                        'member_id' => $creditor_id,
                                        'create_date' => $create_date,
                                    ));
                                }
                            }
                        } else {
                            // แก้ไข
                            $this->db()->update($ar_table, $index->id, $save);
                        }
                        // ส่งค่ากลับ
                        $ret['alert'] = Language::get('Saved successfully');
                        $ret['location'] = $request->getUri()->postBack('index.php', array('module' => 'ar-detail', 'id' => $save['id']));
                    }
                } catch (\Kotchasan\InputItemException $e) {
                    $ret['alert'] = $e->getMessage();
                }
            }
        }
        if (empty($ret)) {
            $ret['alert'] = Language::get('Unable to complete the transaction');
        }
        // คืนค่าเป็น JSON
        echo json_encode($ret);
    }

    /**
     * @param $save
     */
    private function newCustomer($save)
    {
        $where = array(
            array('name', $save['name']),
        );
        if ($save['id_card'] != '') {
            $where[] = array('id_card', $save['id_card']);
        }
        $search = $this->db()->createQuery()
            ->from('user')
            ->where($where, 'OR')
            ->first('id');
        if (!$search) {
            $this->db()->insert($this->getTableName('user'), array(
                'status' => 0,
                'permission' => '',
                'create_date' => date('Y-m-d H:i:s'),
                'name' => $save['name'],
                'sex' => $save['sex'],
                'phone' => $save['phone'],
                'id_card' => $save['id_card'],
                'expire_date' => $save['expire_date'],
                'address' => $save['address'],
                'provinceID' => $save['provinceID'],
                'zipcode' => $save['zipcode'],
            ));
        }
    }
}
