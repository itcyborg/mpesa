# Stacks Mpesa
## https://stackbooks.org

### Mpesa Library
**Installation**

`composer require stacks/mpesa`

**Account setup and authentication**
`

    <?php
    use Stacks\Mpesa\Account;
    
    $key='application-key';
    $secret='application secret';
    
    // authentication is handled automatically when the credentials are valid
    $account = new Account($key,$secret);
    
`

**Register Customer to Business Callbacks**
`

    <?php
    use Stacks\Mpesa\Account;
    
    $key='application-key';
    $secret='application secret';
    $shortcode='pabill or till shortcode provided';
    $confirm_url='https://example.com/confirm';
    $validate_url='https://example.com/validate';
    
    // authentication is handled automatically when the credentials are valid
    $account = new Account($key,$secret,null,$shortcode);
    
    // default command used is 'Completed'
    $account->register($confirm_url,$validate_url);
    
    //to  override the default, i.e in cases where you need validation, pass a third parameter 'Cancelled/Completed'
    $account->register($confirm_url,$validate_url,'Cancelled');
