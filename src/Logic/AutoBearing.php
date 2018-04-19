<?php
namespace Loid\Module\Lbb\Logic;

use Loid\Module\Lbb\Model\UserFinancial as UserFinancialModel;
use Loid\Module\Lbb\Model\Store as StoreModel;
use Loid\Module\Lbb\Logic\Store as StoreLogic;
use Loid\Module\Lbb\Model\BusinessSet as BusinessSetModel;
use DB;
use Log;

class AutoBearing{
    
    
    public static function execute(){
        //这里有个小逻辑，先处理余额计息，再处理定存宝计息，因为余额三天后计息，若有变动则重置时间
        $object = new self;
        $object->balance();
        $object->financial();
    }
    
    /**
     * 处理余额计息,按三天内最低额计息
     */
    public function balance(){
        $time = date('Y-m-d H:i:s', strtotime('-3 day'));
        DB::table('lbb_store_change')->where('created_at', '<', $time)->delete();
        $balance_rate = (new BusinessSetModel)->getBusiness('balance_rate');
        if (doubleval($balance_rate) <= 0) {
            Log::emergency('余额计息失败，余额利息小于等于0');
            return false;
        }
        foreach (StoreModel::get() as $val) {
            //判断上次余额计息时间，如果当天计息一生效，则不再计息
            
            if (DB::table('lbb_store_log')->where('user_id', $val->user_id)->where('store_id', $val->store_id)->where('flag', 'interest')->where('created_at', '>', date('Y-m-d'))->count()) {
                Log::emergency('仓库ID【' . $val->store_id . '】' . date('Y-m-d') . '日余额计息已完成，一天只能计息一次');
                continue;
            }
            try {
                DB::beginTransaction();
                //获取该分类最近三天的最低金额
                $minNum = DB::table('lbb_store_change')
                    ->where('created_at', '>', $time)
                    ->where('user_id', $val->user_id)
                    ->where('store_category', $val->store_category)
                    ->min('last_num');
                $minNum = $minNum ?? $val->store_num;
                if ($minNum > 0) {
                    //利息
                    $interest = bcmul($minNum, bcdiv($balance_rate, 100, 6), 6);
                    $json = $val->toJson();
                    $val->store_num = bcadd($val->store_num, $interest, 6);
                    $val->save();
                    //仓库变动记录
                    (new StoreModel)->storeChange($val->user_id, $val->store_id, $val->store_category, $val->store_num, $interest, 'interest', $json);
                    //3级上级收益入库
                    (new StoreLogic)->promoteIncome($val, $interest, 'balance_promote');
                }
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('余额计息失败：store_id='. $val->store_id . '；原因：' . $e->getMessage());
            }
        }
    }
    
    /**
     * 处理定存宝到期计息
     */
    private function financial(){
        while($userFinancial = UserFinancialModel::where('closed_date', '<', date('Y-m-d H:i:s'))->where('financial_status', 'on')->first()){
            try {
                DB::beginTransaction();
                $userFinancial->financial_status = 'off';
                $userFinancial->save();
                //定存宝本金+收益
                $num = bcadd($userFinancial->num, $userFinancial->financial_num, 6);
                $userStore = (new StoreLogic)->getStoreByCategory2User($userFinancial->user_id, $userFinancial->financial_category);
                $userStore->store_num = bcadd($userStore->store_num, $num, 6);
                $userStore->save();
                
                //仓库变动记录
                (new StoreModel)->storeChange($userFinancial->user_id, $userStore->store_id, $userFinancial->financial_category, $userStore->store_num, bcadd($userFinancial->num, $userFinancial->financial_num, 6), 'expire', $userFinancial->toJson());
                //3级上级收益入库
                (new StoreLogic)->promoteIncome($userStore, $userFinancial->financial_num, 'financial_promote');
                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('定存宝到期计息失败：id='. $userFinancial->id . '；原因：' . $e->getMessage());
            }
        }
    }
}