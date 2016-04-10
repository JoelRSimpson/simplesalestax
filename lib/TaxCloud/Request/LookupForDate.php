<?php

/**
 * Portions Copyright (c) 2009-2012 The Federal Tax Authority, LLC (FedTax).
 * All Rights Reserved.
 *
 * This file contains Original Code and/or Modifications of Original Code as
 * defined in and that are subject to the FedTax Public Source License (the
 * ‘License’). You may not use this file except in compliance with the License.
 * Please obtain a copy of the License at http://FedTax.net/ftpsl.pdf or
 * http://dev.taxcloud.net/ftpsl/ and read it before using this file.
 *
 * The Original Code and all software distributed under the License are
 * distributed on an ‘AS IS’ basis, WITHOUT WARRANTY OF ANY KIND, EITHER
 * EXPRESS OR IMPLIED, AND FEDTAX  HEREBY DISCLAIMS ALL SUCH WARRANTIES,
 * INCLUDING WITHOUT LIMITATION, ANY WARRANTIES OF MERCHANTABILITY, FITNESS FOR
 * A PARTICULAR PURPOSE, QUIET ENJOYMENT OR NON-INFRINGEMENT.
 *
 * Please see the License for the specific language governing rights and
 * limitations under the License.
 *
 *
 *
 * Modifications made August 20, 2013 by Brian Altenhofel
 */

namespace TaxCloud\Request;

use TaxCloud\Request\RequestBase;

class LookupForDate extends RequestBase
{
  protected $customerID; // string
  protected $cartID; // string
  protected $cartItems; // ArrayOfCartItem
  protected $origin; // Address
  protected $destination; // Address
  protected $deliveredBySeller; // boolean
  protected $exemptCert; // ExemptionCertificate
  protected $useDate; // dateTime

  public function __construct($apiLoginID, $apiKey, $customerID, $cartID, $cartItems, $origin, $destination, $deliveredBySeller, $exemptCert, $useDate)
  {
    $this->setCustomerID($customerID);
    $this->setCartItems($cartItems);
    $this->setOrigin($origin);
    $this->setDestination($destination);
    $this->setDeliveredBySeller($deliveredBySeller);
    $this->setExemptCert($exemptCert);
    $this->setUseDate($useDate);
    parent::__construct($apiLoginID, $apiKey);
  }

  private function setCustomerID($customerID)
  {
    $this->customerID = $customerID;
  }

  public function getCustomerID()
  {
    return $this->customerID;
  }

  private function setCartID($cartID)
  {
    $this->cartID = $cartID;
  }

  public function getCartID()
  {
    return $this->cartID;
  }

  private function setCartItems($cartItems)
  {
    $this->cartItems = $cartItems;
  }

  public function getCartItems()
  {
    return $this->cartItems;
  }

  private function setOrigin(Address $origin)
  {
    $this->origin = $origin;
  }

  public function getOrigin()
  {
    return $this->origin;
  }

  private function setDestination(Address $destination)
  {
    $this->destination = $destination;
  }

  public function getDestination()
  {
    return $this->destination;
  }

  private function setDeliveredBySeller($deliveredBySeller)
  {
    $this->deliveredBySeller = $deliveredBySeller;
  }

  public function getDeliveredBySeller()
  {
    return $this->deliveredBySeller;
  }

  private function setExemptCert($exemptCert)
  {
    $this->exemptCert = $exemptCert;
  }

  public function getExemptCert()
  {
    return $this->exemptCert;
  }

  private function setUseDate($useDate)
  {
    $this->useDate = $useDate;
  }

  public function getUseDate()
  {
    return $this->useDate;
  }
}
