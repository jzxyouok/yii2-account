<?php

namespace lubaogui\account;

use yii\base\Component;
use yii\helpers\ArrayHelper;
use lubaogui\account\models\UserAccount;
use lubaogui\account\models\Trans;
use lubaogui\account\models\Bill;
use lubaogui\payment\Payment;
use lubaogui\account\behaviors\ErrorBehavior;;
use yii\base\Exception;

/**
 * 该类属于对账户所有对外接口操作的一个封装，账户的功能有充值，提现，担保交易，直付款交易等,账户操作中包含利润分账，但是分账最多支持2个用户分润
 */
class BaseAccount extends Component 
{

    private $config;  

    /**
     * @brief 
     *
     * @return  public function 
     * @retval   
     * @see 
     * @note 
     * @author 吕宝贵
     * @date 2015/12/06 10:12:27
     **/
    public function init() {
        $this->config = ArrayHelper::merge(
            require(__DIR__ . '/config/main.php'),
            require(__DIR__ . '/config/main-local.php')
        );
    }

    /**
     * @brief 默认的错误behaviors列表，此处主要是追加错误处理behavior
     *
     * @return  public function 
     * @retval   
     * @see 
     * @note 
     * @author 吕宝贵
     * @date 2015/12/30 16:55:03
    **/
    public function behaviors() {
        return [
            ErrorBehavior::className(),
        ];
    }

    /**
     * @brief 获取某个账户, 如果用户账户不存在，则自动为用户开通一个账号,账户信息不允许被缓存，当开启一个事务时，必须
     * 重新加载账号
     *
     * @return  object 用户账户对象 
     * @see 
     * @note 
     * @author 吕宝贵
     * @date 2015/12/06 17:59:27
     **/
    public function getUserAccount($uid) {
        if (empty($uid)) {
            $this->addError('display-error', '提交的用户id为空');
            return false;
        }
        $userAccount = UserAccount::findOne($uid);
        if (!$userAccount) {
            $userAccount = new UserAccount();
            $userAccount->uid = $uid;
            $userAccount->balance = 0;
            $userAccount->frozen_money = 0;
            $userAccount->deposit = 0;
            $userAccount->currency = 1; //默认只支持人民币
            if (!$userAccount->save()) {
                $this->addErrors($userAccount->getErrors());
                return false; 
            }
        }
        return  $userAccount;
    }


    /**
     * @brief 获取公司付款账号
     *
     * @return  public function 
     * @retval   
     * @see 
     * @note 
     * @author 吕宝贵
     * @date 2016/01/07 10:57:17
    **/
    public function getCompanyPayAccount() {
        $companyAccount = UserAccount::find()->where(['type'=>UserAccount::ACCOUNT_TYPE_SELFCOMPANY_PAY])->one();
        if (! $companyAccount) {
            throw new Exception('必须设置公司付款账号');
        }
        return $companyAccount;

    }

    /**
     * @brief 担保交易中间账号
     *
     * @return  public function 
     * @retval   
     * @see 
     * @note 
     * @author 吕宝贵
     * @date 2016/01/07 11:06:18
    **/
    public function getVouchAccount() {
        $vouchPayAccount = UserAccount::find()->where(['type'=>UserAccount::ACCOUNT_TYPE_SELFCOMPANY_VOUCH])->one();
        if (! $vouchPayAccount) {
            throw new Exception('必须设置担保交易账号');
        }
        return $vouchPayAccount;
    }

    /**
     * @brief 利润账号
     *
     * @return  public function 
     * @retval   
     * @see 
     * @note 
     * @author 吕宝贵
     * @date 2016/01/07 12:07:15
    **/
    public function getProfitAccount() {
        $profitAccount = UserAccount::find()->where(['type'=>UserAccount::ACCOUNT_TYPE_SELFCOMPANY_PROFIT])->one();
        if (! $profitAccount) {
            throw new Exception('必须设置利润账号');
        }
        return $profitPayAccount;
    }

    /**
     * @brief 手续费账号
     *
     * @return  public function 
     * @retval   
     * @see 
     * @note 
     * @author 吕宝贵
     * @date 2016/01/07 12:07:47
    **/
    public function getFeeAccount() {

        $feeAccount = UserAccount::find()->where(['type'=>UserAccount::ACCOUNT_TYPE_SELFCOMPANY_FEE])->one();
        if (! $feeAccount) {
            throw new Exception('必须设置担保交易账号');
        }
        return $feeAccount;
    }

    /**
     * @brief 每个交易最后确认交易完成时所进行的操作，主要为打款给目的用户，分润，收取管理费等
     *
     * @return  protected function 
     * @retval   
     * @see 
     * @note 
     * @author 吕宝贵
     * @date 2015/12/06 17:31:52
     **/
    protected function finishPayTrans($trans) {

        //收款账户处理逻辑
        $sellerAccount = UserAccount::findOne($trans->to_uid);
        if (!$sellerAccount->plus($money, $trans, '产品售卖收入')) {
            return false;
        }

        //分润账号处理逻辑
        if ($trans->profit > 0) {
            return $this->processProfit('pay', $trans);
        }

        //手续费逻辑处理
        if ($trans->fee > 0) {
            return $this->processFee('pay', $trans);
        }

    }

    /**
     * @brief 退款的后续处理操作，完成交易和付款成功有不同的退款逻辑, 此方法主要完成退款后买方和手续费等处理操作 
     *
     * @return  protected function 
     * @retval   
     * @see 
     * @note 
     * @author 吕宝贵
     * @date 2015/12/06 18:02:59
     **/
    protected function finishRefundTrans($trans) {

        //退款是否收取手续费,可以在这里做逻辑判断,此处退款退给用户多少钱，需要确定
        $buyerAccount = UserAccount::findOne($trans->from_uid); 
        if (!$buyerAccount->plus($trans->total_money - $trans->earnest_money, $trans, '产品退款')) {
            return false;
        }

        //对于支付交易已经完成的订单，需要退款手续费,利润，还有保证金等操作，一期先不做。
        if ($trans->status = Trans::PAY_STATUS_FINISHED) {
            throw new Exception('对不住，交易处于此种状态目前并不支持退款操作, 请联系客服人员');
        }

        //保存交易状态
        $trans->status = Trans::PAY_STATUS_REFUNDED;
        $trans->save();

        return true;
    }

    /**
     * @brief 
     *
     * @return  protected function 
     * @retval   
     * @see 
     * @note 
     * @author 吕宝贵
     * @date 2015/12/05 12:45:03
     **/
    protected function processProfit($action, $trans) {
        $profitAccount = $this->getProfitAccount();
        switch ($action) {
        case 'pay': {
            $profitAccount->plus($money, $trans, '利润收入');
            break;
        }
        case 'refund': {
            $profitAccount->minus($money, $trans, '利润退款');
            break;
        }
        default:break;
        }
    }

    /**
     * @brief 
     *
     * @return  protected function 
     * @retval   
     * @see 
     * @note 
     * @author 吕宝贵
     * @date 2015/12/05 12:45:52
     **/
    protected function processFee($action, $trans) {

        $profitAccount = $this->getProfitAccount();
        switch ($action) {
        case 'pay': {
            $profitAccount->plus($money, $trans, '手续费收入');
            break;
        }
        case 'refund': {
            $profitAccount->minus($money, $trans, '手续费退款');
            break;
        }
        default:break;
        }

    }

}
